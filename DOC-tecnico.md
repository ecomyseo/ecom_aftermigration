# ecom_aftermigration — documentación técnica

Versión 1.0.0 · PrestaShop 1.7.6 → 9.x · PHP 7.4 → 8.3

Módulo y overrides para reparar los daños que deja una actualización o una migración de
PrestaShop. Cada apartado cita el fichero y la línea del núcleo donde está el fallo, y
qué se hace exactamente para taparlo.

---

## 1. Fallo: fatal al validar un pedido

### Traza

```
Fatal error: Uncaught TypeError: array_key_exists(): Argument #2 ($array) must be of
type array, null given in classes/AddressFormat.php:446
#0 classes/pdf/HTMLTemplateInvoice.php(167): AddressFormatCore::generateAddress()
#1 classes/pdf/PDF.php(146): HTMLTemplateInvoiceCore->getContent()
#2 classes/PaymentModule.php(621): PDFCore->render()
#3 modules/ps_wirepayment/controllers/front/validation.php(63): PaymentModuleCore->validateOrder()
```

### Causa

`classes/pdf/HTMLTemplateInvoice.php`, líneas 162-163 en 8.1 y 8.2, 139-140 en 9.1:

```php
$invoiceAddressPatternRules = json_decode(Configuration::get('PS_INVCE_INVOICE_ADDR_RULES'), true);
$deliveryAddressPatternRules = json_decode(Configuration::get('PS_INVCE_DELIVERY_ADDR_RULES'), true);
```

Si la clave no existe, `Configuration::get()` devuelve `false`, `json_decode(false, true)`
devuelve `null`, y ese `null` llega tal cual a `AddressFormat::generateAddress()`, que en
`classes/AddressFormat.php:446` (424 en 9.1) hace:

```php
if (!array_key_exists('avoid', $patternRules) || !in_array($pattern, $patternRules['avoid'])) {
```

En PHP 7 eso era un *warning* y el pedido salía adelante. En PHP 8 es un `TypeError`.
Como el PDF se genera dentro de `PaymentModule::validateOrder()`, el pedido muere al
validarse y el cliente se queda sin poder pagar.

Las dos claves vienen de fábrica con `{"avoid":[]}`
(`install/data/xml/configuration.xml`, líneas 116 y 119 en 8.2.7). Hay migraciones que
no las traen.

### Arreglo

**De datos** — chequeo `pdf_addr_rules`, en
`classes/checks/EcomAmCheckPdfAddrRules.php`. Recorre todas las tiendas con
`Shop::getShops(false, null, true)` y, para cada clave cuyo valor no decodifique a un
array, escribe `{"avoid":[]}` con
`Configuration::updateValue($clave, $valor, false, null, $idShop)`. Después vuelve a
leer y solo da el arreglo por bueno si no queda ninguna rota.

Equivalente en SQL: `sql/01-reglas-direccion-pdf.sql`.

**Red de seguridad** — `override/classes/AddressFormat.php`. No copia el cuerpo del
método: normaliza los argumentos y delega en el padre.

```php
if (!is_array($patternRules)) { $patternRules = array(); }
if (!isset($patternRules['avoid']) || !is_array($patternRules['avoid'])) {
    $patternRules['avoid'] = array();
}
return parent::generateAddress($address, $patternRules, $newLine, $separator, $style);
```

Poner `avoid => array()` no cambia el comportamiento: `!in_array($pattern, array())` es
siempre `true`, exactamente lo mismo que cuando no hay reglas. Verificado en la prueba
`generateAddress(array vacio) da lo mismo que con null`.

---

## 2. Fallo: Pedidos > Facturas caída

### Traza

```
Expected argument of type "object, array or empty", "string" given
Symfony\Component\Form\Exception\UnexpectedTypeException
  PropertyPathMapper.php(44) -> mapDataToForms
  FormBuilder.php(208) -> getForm
  src/Core/Form/Handler.php(114)
  src/PrestaShopBundle/Controller/Admin/Sell/Order/InvoicesController.php(57)
```

### Causa

`InvoicesController::indexAction()` línea 57 pide el formulario de opciones de factura.
`InvoiceOptionsType` declara tres campos `TranslatableType` —`invoice_prefix`,
`legal_free_text` y `footer_text`— que esperan un **array indexado por id_lang**.

El dato lo pone `InvoiceOptionsConfiguration::getConfiguration()` con
`$this->configuration->get('PS_INVOICE_PREFIX')`, y el adaptador
`PrestaShop\PrestaShop\Adapter\Configuration::get()` decide el formato así (línea 122):

```php
if (ConfigurationLegacy::isLangKey($key)) {
    return $this->getLocalized($key, $shopId, $shopGroupId);   // array por idioma
}
```

Y `Configuration::isLangKey()` mira una tabla que se rellena en
`Configuration::loadConfiguration()` (línea 152), con un `LEFT JOIN` a
`ps_configuration_lang`:

```php
$lang = ($row['id_lang']) ? $row['id_lang'] : 0;
self::$types[$row['name']] = (bool) $lang;
```

Si una clave se queda **sin ninguna fila** en `ps_configuration_lang`, el `LEFT JOIN`
devuelve `id_lang = NULL`, `$types` pasa a `false`, el adaptador devuelve una **cadena**,
y `PropertyPathMapper` revienta.

Ojo al detalle: el bucle **sobrescribe** `$types[$name]` en cada fila. Con multitienda,
una clave con fila global + fila por tienda donde solo una tiene traducciones puede
quedarse en `false` según el orden de las filas.

Afecta igual a Albaranes, Facturas por abono, Devoluciones de mercancía, Preferencias de
producto y Mantenimiento, que usan los mismos campos traducibles.

### Claves multiidioma del núcleo

Las tres primeras salen de `install/langs/<iso>/data/configuration.xml`; el resto, de los
formularios del back-office que las declaran `TranslatableType`:

```
PS_INVOICE_PREFIX            PS_DELIVERY_PREFIX            PS_RETURN_PREFIX
PS_CREDIT_SLIP_PREFIX        PS_INVOICE_LEGAL_FREE_TEXT    PS_INVOICE_FREE_TEXT
PS_SEARCH_BLACKLIST          PS_CUSTOMER_SERVICE_SIGNATURE PS_MAINTENANCE_TEXT
PS_LABEL_IN_STOCK_PRODUCTS   PS_LABEL_OOS_PRODUCTS_BOA     PS_LABEL_OOS_PRODUCTS_BOD
```

A esa lista fija el chequeo le suma, en ejecución, **toda clave que ya tenga al menos una
fila** en `ps_configuration_lang`: así también cubre las de módulos de terceros.

### Arreglo

Chequeo `configuration_lang`, en `classes/checks/EcomAmCheckConfigLang.php`.

* Los idiomas se sacan de `ps_lang` **enteros, activos o no**, porque eso es lo que
  recorre `getLocalized()` con `Language::getIDs(false, ...)`.
* Para cada `id_configuration` de una clave multiidioma, inserta las filas que falten con
  `INSERT IGNORE`.
* El valor de una fila nueva sale, por este orden: del idioma por defecto, de cualquier
  otro idioma de esa misma clave, de `ps_configuration.value`, o cadena vacía.
* Al terminar vacía la caché estática (`Configuration::resetStaticCache()`, o
  `clearConfigurationCacheForTesting()` en 1.7), recarga y **vuelve a contar**.
* Cuenta, pero **no borra**, las filas huérfanas y las de idiomas que ya no existen. El
  SQL para eso está en `sql/03-limpiar-filas-huerfanas.sql`, comentado.

Equivalente en SQL: `sql/02-configuration-lang-multiidioma.sql`.

---

## 3. Fallo: array que llega a `pSQL()`

### Traza

```
TypeError: strip_tags(): Argument #1 ($string) must be of type string, array given
in classes/db/Db.php:795
  DbCore->escape()   config/alias.php(47)
  pSQL()             classes/ObjectModel.php(544)
  ObjectModelCore::formatValue()   classes/ObjectModel.php(474)
  ObjectModelCore->formatFields()  classes/ObjectModel.php(320)
  ObjectModelCore->getFields()     classes/ObjectModel.php(804)
  ObjectModelCore->update()        classes/controller/AdminController.php(1293)
```

### Causa

`DbCore::escape()`, `classes/db/Db.php:790`:

```php
if (!is_numeric($string)) {
    $string = $this->_escape($string);
    if (!$html_ok) {
        $string = strip_tags(Tools::nl2br($string));
    }
```

Con el controlador PDO, `DbPDOCore::_escape()` resuelve con `str_replace()`, **que con un
array devuelve un array**. `Tools::nl2br()` hace lo mismo. Y `strip_tags()` de PHP 8 ya
no acepta un array.

Con el controlador MySQLi la traza sería distinta (`real_escape_string`), así que una
traza que apunte a `Db.php:795` indica PDO.

Llega aquí cualquier módulo que guarde un `ObjectModel` con un campo `TYPE_STRING` cuyo
valor sea un array: un campo multiidioma sobre una columna que no lo es, un
`<select multiple>`, un `name[]` en el formulario. En PHP 7 `strip_tags()` devolvía
`null` y el campo se guardaba vacío, sin avisar a nadie. En PHP 8 se cae la petición.

**El módulo culpable hay que arreglarlo igualmente.** El override tapa el fatal y
apunta quién es.

### Arreglo

`override/classes/db/Db.php`, declarado como `abstract class Db extends DbCore` (tiene
que ser abstracto: `DbCore` tiene métodos abstractos y `DbPDO` hereda de `Db`).

Camino rápido primero, para no penalizar el millón de llamadas normales:

```php
if (is_scalar($string)) {
    return parent::escape($string, $html_ok, $bq_sql);
}
```

Y si no es escalar: `null` → `''`; array → se aplana; objeto con `__toString` → cadena;
recurso u otra cosa → `''`.

El aplanado depende de la opción **Array que llega a pSQL()**:

| Modo | Qué hace |
|---|---|
| `idioma` (por defecto) | Devuelve el valor del idioma por defecto; si está vacío, el primero que no lo esté. Es lo que salva el dato en el caso típico, el campo multiidioma. |
| `unir` | `implode(',', ...)`. Para un `<select multiple>`. |
| `vaciar` | Cadena vacía. Reproduce lo que hacía PHP 7. |

**Las opciones NO se leen de `Configuration`.** `Configuration::loadConfiguration()` llama
a `bqSQL()`, o sea a `pSQL()`, o sea a `escape()`: leer configuración desde dentro de
`escape()` es reentrante. Se leen de un fichero de texto plano,
`modules/ecom_aftermigration/logs/escape.conf`, que el módulo reescribe al guardar:

```
modo=idioma
log=1
id_lang=1
```

El registro va a `logs/escape.log`, con tope de 20 líneas por petición, sin repetir la
misma firma, y con los tres primeros marcos de la pila que no sean `Db.php` ni
`alias.php`. Ese es el módulo a corregir.

`logs/` lleva su `index.php` y un `.htaccess` que deniega todo. **En Nginx hay que añadir
la regla a mano**, porque el `.htaccess` no se aplica.

---

## 4. Fallo: filas que faltan en las tablas `*_lang`

Chequeo `lang_rows`, en `classes/checks/EcomAmCheckLangRows.php`.

La detección cuenta filas por idioma (`GROUP BY id_lang`, barrido de índice) y marca como
coja toda tabla donde algún idioma tenga menos filas que el que más tiene. La reparación
copia con `INSERT IGNORE ... SELECT` desde el idioma de referencia, así que nunca pisa lo
que ya esté traducido.

**El filtro de tablas es la parte importante.** Solo entran las que cumplen las dos cosas:

* el nombre acaba en `_lang`, y
* `id_lang` está dentro de la **clave primaria**.

Hay tablas con columna `id_lang` que no son tablas de traducción —`ps_customer`,
`ps_employee`, `ps_search_word`—. Copiar sus filas a otro idioma duplicaría clientes.

Las tablas con más filas que el tope configurado (200.000 por defecto) solo se informan.

Este chequeo **no borra nunca**. Las filas de idiomas que ya no existen se cuentan y el
SQL está en `sql/03`, comentado.

---

## Estructura

```
ecom_aftermigration/
  ecom_aftermigration.php          Ecom_Aftermigration extends Module
  classes/
    EcomAmLog.php                  registros + escritura de logs/escape.conf
    EcomAmCheck.php                clase base abstracta de un chequeo
    EcomAmDiagnostico.php          registro y ejecución de los chequeos
    EcomAmOverrides.php            copia manual de override_v8/ a override/
    checks/                        un fichero por chequeo
  override_v8/classes/
    AddressFormat.php
    db/Db.php
  views/{css,js,templates/admin}/
  translations/es-ES/ModulesEcomaftermigrationAdmin.es-ES.xlf
  sql/ · logs/ · index.php en cada carpeta
```

Estructura legacy: sin `src/`, sin `config/services.yml`, sin Composer, sin `vendor/`.
Las clases se cargan con `require_once` desde el fichero principal. Las migraciones van en
`addnewfeatures()`, que corre al abrir la configuración, no en ficheros `upgrade-*.php`.

### Configuración

| Clave | Por defecto | Ámbito |
|---|---|---|
| `ECOM_AM_ESCAPE_MODO` | `idioma` | **global** |
| `ECOM_AM_ESCAPE_LOG` | `1` | **global** |
| `ECOM_AM_LANG_MAX` | `200000` | **global** |
| `ECOM_AM_DEBUG` | `0` | **global** |
| `ECOM_AM_VERSION_DATOS` | versión | **global** |

Todas se guardan con `Configuration::updateValue()` sin `id_shop`, es decir **globales**:
son opciones de una herramienta de mantenimiento, no del escaparate. Los **chequeos** sí
son conscientes de la multitienda: `pdf_addr_rules` recorre todas las tiendas y
`configuration_lang` trabaja por `id_configuration`, que ya distingue tienda.

### Formulario

Un solo `HelperForm` con pestañas, claves `amcorreccion` y `amregistro`. El prefijo `am`
no es decorativo: `js/admin.js` del back-office convierte la clave de la pestaña en un
`id` del DOM y mueve los campos con `appendTo('#'+clave)`. Una clave como `content` o
`general` choca con un `id` que ya existe, saca los campos fuera del `<form>` y esa
pestaña deja de guardarse diciendo «Configuración guardada».

`Module::trans()` es `protected` en el núcleo. Está redeclarado `public` con la firma
exacta para poder llamarlo desde `classes/`. Renombrarlo rompería el extractor de cadenas.

### Traducciones

Sistema nuevo (`isUsingNewTranslationSystem()` devuelve `true`). Dominio
`Modules.Ecomaftermigration.Admin`, fichero
`translations/es-ES/ModulesEcomaftermigrationAdmin.es-ES.xlf`. El nombre lo impone
`TranslatorLanguageLoader::loadModuleTranslations()` con el patrón
`#^ModulesEcomaftermigration[A-Z][\w.-]+\.es-ES\.xlf$#`.

Las cadenas que se traducen indirectamente (`EcomAmCheck::t()`, `EcomAmOverrides::t()`)
llegan a `trans()` dentro de una variable y el extractor no las ve. Están declaradas como
literales en `Ecom_Aftermigration::cadenasParaElExtractor()`, un método que no llama nadie.

---

## Los overrides

Se copian **a mano**, nunca desde `install()`: `installOverrides()` del núcleo fusiona
ficheros y puede romper los de otros módulos. La copia la dispara el botón **Instalar los
overrides** o `scripts/instalar-overrides.php`.

Llevan la marca `ECOM_AFTERMIGRATION_OVERRIDE` en la cabecera. Si el destino existe y
**no** lleva esa marca, no se toca: se avisa para fusionarlo a mano. Si lleva la marca, se
guarda una copia `.bak-<fecha>` antes de pisarlo. Al quitarlos solo se borran los que la
llevan.

Después de copiar se borra **todo** `class_index.php` (`var/cache/dev/` y `var/cache/prod/`
por separado: el back-office y el front pueden correr en entornos distintos) y se regenera
el índice, que es lo que decide si un override se carga. El nombre de la clase cambia
según la versión, así que se prueban las dos:

| Versión | Clase |
|---|---|
| 8.2 y 9.x | `PrestaShop\Autoload\PrestashopAutoload` — ojo a la **s minúscula** de «shop» |
| 1.7 y 8.0 / 8.1 | `PrestaShopAutoload`, en el espacio de nombres global |

**Y nunca `Tools::clearAllCache()` ni `Tools::clearSf2Cache()`.** Lo avisa el propio
núcleo en el comentario de `clearSf2Cache()` (`classes/Tools.php:3350`): *«it can result
in unexpected behaviour with Container rebuild»*. Medido en la 8.2.7 local: al pulsar
**Instalar los overrides**, la petición siguiente devolvía un **500** y la pantalla de
configuración se quedaba sin pintar. Para que un override legacy entre en juego basta con
el índice de clases; la caché de Symfony no pinta nada ahí. Solo se vacía `clearSmartyCache()`,
que es barata.

Si `PS_DISABLE_OVERRIDES` está a 1, o la constante `_PS_DISABLE_OVERRIDES_` está definida,
PrestaShop los ignora todos. El chequeo `overrides` lo detecta y lo dice con un aviso rojo.

### Por qué el override de `Db` es seguro de cargar tan pronto

`_PS_VERSION_` se define en `config/autoload.php` (línea 32 en 8.2.7) **antes** de
registrar el autoloader, así que la guarda `if (!defined('_PS_VERSION_')) exit;` nunca
mata una carga legítima de `Db`.

---

## Alternativa: parchear el núcleo

En `diffs/` hay nueve parches unificados, tres cambios × tres versiones (1.7.8.11, 8.2.7,
9.1.1), generados contra el fuente real y comprobados con `patch -p1 --dry-run` más
`php -l` en 7.4, 8.1 y 8.3.

**No es lo recomendado**: un parche del núcleo se pierde en la siguiente actualización.
Están para ver de un vistazo qué cambia y para quien ya tenga el núcleo tocado.

---

## Empaquetar

```
python herramientas/hacer_zip.py
```

Deja `ecom_aftermigration.zip` en la raíz del paquete y **comprueba** que dentro está
`ecom_aftermigration/ecom_aftermigration.php` y que no hay más de una carpeta raíz.

Comprimir a mano la carpeta de trabajo **no vale**: se llama igual que el módulo, así que
el ZIP saldría con `ecom_aftermigration/ecom_aftermigration/ecom_aftermigration.php`. El
instalador busca `ecom_aftermigration/ecom_aftermigration.php`, encuentra una carpeta, y
contesta *«Este archivo no es un módulo en formato ZIP válido»*. Además arrastraría al
cliente los guiones, los SQL, los diffs y los manuales.

El guion deja fuera `*.log`, `*.bak` y `logs/escape.conf`, que son estado de ejecución.

---

## Pruebas

### De los overrides, sin tienda

```
php pruebas/test_overrides.php
```

Dos fases en dos procesos, porque `Db` y `AddressFormat` solo se pueden declarar una vez:

1. **El núcleo tal cual** (copias *verbatim* de 8.2.7 en `pruebas/nucleo_simulado.php`)
   tiene que reventar con los dos `TypeError`. En PHP 7.4 esta fase se salta, porque ahí
   eran avisos.
2. **Con los overrides**: no revientan, el dato se conserva, y siguen escapando comillas,
   quitando etiquetas y respetando `avoid` y `html_ok`.

Resultado medido: 2 + 11 comprobaciones en verde en PHP 8.1 y 8.3; 0 + 11 en PHP 7.4.

La fase 1 es lo que le da valor a la prueba: si alguien deshace el arreglo, la fase 2 se
pone roja; y si algún día PrestaShop arregla el núcleo, se pone roja la fase 1 y esto se
puede tirar.

### De la pantalla, contra una tienda de verdad

```
python pruebas/probar_pantalla.py 8.2b
```

Entra al back-office con la sesión real, abre la configuración, pulsa **cada** botón y
comprueba contra la base de datos que lo que dice la pantalla ha pasado. Sin navegador:
cookies y el HTML servido.

Cubre: que la pantalla abre sin errores de PHP ni de SQL, que los cinco chequeos se
pintan, cada botón **Arreglar**, instalar y quitar los overrides, que el front y el
back-office siguen en pie con los overrides puestos, y que el formulario guarda de verdad
—leyendo `ps_configuration`, no el HTML— y sale marcado al recargar.

Medido en la 8.2.7 limpia con otros diez overrides ya instalados: **33 comprobaciones en
verde, 0 fallos**.

Lo que esto **no** puede ver es lo que mueve el JavaScript del back-office al repartir los
campos por pestañas. Para eso haría falta un navegador. Lo que sí se comprueba por HTTP es
que las claves de pestaña llevan el prefijo `am`, que ninguna se llama `content` ni
`general`, y que los cuatro campos viajan en el mismo `<form>`.

---

## Limitaciones conocidas

* El override de `Db` **no arregla el módulo culpable**, solo evita el fatal y lo apunta.
* `lang_rows` copia filas del idioma con más datos: los textos quedan en el idioma de
  origen hasta que alguien los traduzca. Es mejor que una ficha en blanco, no es una
  traducción.
* El `.htaccess` de `logs/` no protege en Nginx.
* El chequeo `pdf_addr_rules` escribe las claves como globales por tienda; si el comercio
  usa grupos de tiendas con valores distintos por grupo, hay que revisarlo a mano.
* Probado en PrestaShop **8.2.7** con PHP 8.1, monotienda. En 1.7.8, 9.1 y 9.2 lo
  verificado es el código del núcleo contra el que se trabaja, los parches (aplicados y
  lintados) y las pruebas de los overrides; la pantalla no se ha abierto ahí.
* Multitienda: los chequeos recorren todas las tiendas, pero no se ha probado con la
  multitienda activada de verdad.
