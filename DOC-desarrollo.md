# ecom_aftermigration — manual de desarrollo

Para quien vaya a añadir un fallo nuevo a la caja de herramientas o a tocar lo que ya hay.

---

## La idea

Cada fallo de migración es **un chequeo**. Un chequeo sabe dos cosas:

* **detectar** el fallo sin escribir nada, y
* **arreglarlo** solo cuando alguien pulsa el botón.

Nada se repara solo. Nada se borra. Nunca hay un recorrido automático sobre tablas
históricas: la detección son consultas de lectura, y la reparación la dispara una persona.

---

## Añadir un chequeo

### 1. La clase

`ecom_aftermigration/classes/checks/EcomAmCheckLoQueSea.php`:

```php
<?php
/**
 * Ecom Aftermigration - Lo que sea
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EcomAmCheckLoQueSea extends EcomAmCheck
{
    public function getCodigo()
    {
        return 'lo_que_sea';           // corto, estable: se usa como id del DOM
    }

    public function getTitulo()
    {
        return $this->t('Something to check');
    }

    public function getDescripcion()
    {
        return $this->t('One sentence. The long explanation goes in the manual.');
    }

    public function comprobar()
    {
        $malas = $this->buscar();      // SOLO SELECT

        if (!count($malas)) {
            return $this->resultado(self::ESTADO_OK, $this->t('Nothing wrong here.'));
        }

        return $this->resultado(
            self::ESTADO_FALLO,
            $this->t('%count% row(s) are wrong.', array('%count%' => count($malas))),
            $malas                     // detalle: array de cadenas
        );
    }

    public function arreglar()
    {
        // ... escribir ...

        $quedan = count($this->buscar());   // RELEER, no dar por bueno

        return $this->arreglado(
            $quedan === 0,
            $quedan === 0 ? $this->t('Fixed.') : $this->t('Still wrong.')
        );
    }
}
```

### 2. Registrarlo

En `classes/EcomAmDiagnostico.php`: un `require_once` arriba y una entrada más en el array
del constructor. Nada más.

### 3. Las cadenas

Los literales van **en inglés** dentro de `$this->t('...')`. El castellano vive solo en el
`.xlf`. El ciclo completo, desde la raíz del paquete:

```
python herramientas/extraer_cadenas.py ecom_aftermigration    # lista y escribe _cadenas.json
```

Se añaden las cadenas nuevas a `herramientas/traducciones.json` (clave en inglés, valor en
castellano **con tildes y eñes**) y se regenera todo:

```
python herramientas/generar_xlf.py .
python herramientas/validar_xlf.py ecom_aftermigration/translations/es-ES/ModulesEcomaftermigrationAdmin.es-ES.xlf
```

`generar_xlf.py` para si falta alguna traducción y avisa de las que sobran. Además
reescribe el método `Ecom_Aftermigration::cadenasParaElExtractor()`, entre las marcas
`// <cadenas-extractor>`. Ese método existe porque `$this->t()` mete el literal en una
variable antes de llegar a `trans()`, y el extractor de PrestaShop solo ve literales
pegados a `trans(`.

---

## Reglas que no se saltan

### `comprobar()` no escribe

Se ejecuta en cada carga de la pantalla de configuración. Si escribe, escribe en cada
recarga. Solo `SELECT` y `COUNT`.

### `arreglar()` relee antes de decir que sí

`$this->arreglado($ok, ...)` con `$ok` calculado a partir de una relectura, no del número
de filas que se han tocado. Un `UPDATE` que no da error no significa que el estado haya
quedado bien.

### `executeS()` solo para `SELECT`

`Db::executeS()`, `getRow()` y `getValue()` comprueban que la sentencia empiece por
`SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|CHECKSUM` y lanzan `PrestaShopDatabaseException` con
cualquier otra. `INSERT`, `UPDATE`, `DELETE`, `ALTER` y `ANALYZE` van con `execute()`.

### `(array) $x` no es «un array o vacío»

`(array) false` es `array(0 => false)`. `Db::executeS()` devuelve `false` cuando no hay
nada. Se escribe siempre:

```php
foreach (is_array($filas) ? $filas : array() as $fila) { ... }
```

### Nada de LIKE con el prefijo

`_DB_PREFIX_` lleva un guion bajo, y en un `LIKE` el guion bajo es un comodín: `ps_%`
también casa con `psX...`. El filtro por prefijo se hace en PHP.

### Antes de copiar filas entre idiomas

Comprobar que la tabla es de traducción de verdad: nombre acabado en `_lang` **y**
`id_lang` dentro de la clave primaria. Hay tablas con `id_lang` que no son traducciones
(`ps_customer`, `ps_employee`, `ps_search_word`) y copiarlas duplicaría datos reales.

### Borrar va aparte

Ningún botón del módulo borra filas. Lo que haya que borrar se cuenta, se informa, y el
SQL se deja en `sql/03-limpiar-filas-huerfanas.sql` **comentado**, con el `SELECT` de
recuento delante.

---

## Estados

| Constante | Cuándo | Efecto en pantalla |
|---|---|---|
| `ESTADO_OK` | no hay nada que hacer | verde, sin botón |
| `ESTADO_AVISO` | hay algo que mirar pero no rompe nada | ámbar |
| `ESTADO_FALLO` | rompe una pantalla o un pedido | rojo |

El botón **Arreglar** sale si `puedeArreglar()` devuelve `true` **y** el estado no es
`ESTADO_OK`. Un chequeo que solo informa devuelve `false` en `puedeArreglar()`.

---

## Tocar el override de `Db`

Es el camino más caliente de PrestaShop: se llama en cada `pSQL()`, o sea miles de veces
por petición. Tres cosas que no se pueden romper:

1. **El camino rápido primero.** `if (is_scalar($string)) return parent::escape(...)`.
   Todo lo demás va después.
2. **No leer `Configuration` desde ahí.** `Configuration::loadConfiguration()` llama a
   `bqSQL()` → `pSQL()` → `escape()`. Las opciones se leen de `logs/escape.conf`, en texto
   plano y cacheadas en un `static`.
3. **No llamar a nada de PrestaShop para registrar.** El log se escribe con
   `file_put_contents` a pelo.

Y la clase tiene que declararse `abstract class Db extends DbCore`: `DbCore` tiene métodos
abstractos y `DbPDO` hereda de `Db`.

Después de tocarlo:

```
php pruebas/test_overrides.php
```

Tiene que salir verde en 7.4, 8.1 y 8.3. Si se cambia el código *verbatim* del núcleo que
hay en `pruebas/nucleo_simulado.php`, hay que volver a copiarlo del fuente real de
`C:\dev-tools\prestashop\<versión>\`, no escribirlo de memoria.

---

## Regenerar los parches del núcleo

`diffs/` sale de `herramientas/generar_diffs.py`, que aplica las sustituciones sobre el
fuente real de `C:\dev-tools\prestashop\<versión>\` y comprueba que encajan. Si una deja de
encajar porque PrestaShop cambió el fichero, el guion para en vez de generar un parche
malo.

```
python herramientas/generar_diffs.py diffs
```

Tras regenerarlos:

```
patch -p1 --dry-run < diffs/<el-que-sea>.diff
php -l <fichero parcheado>          # con 7.4, 8.1 y 8.3
```

---

## Vaciar caché desde un módulo: solo el índice de clases

`Tools::clearAllCache()` llama a `Tools::clearSf2Cache()`, y el propio núcleo avisa en el
comentario de ese método (`classes/Tools.php:3350`) de que *«can result in unexpected
behaviour with Container rebuild»*. Medido: llamarlo desde la pantalla del módulo dejaba la
**petición siguiente en un 500**.

Para que un override legacy entre en juego basta con borrar `class_index.php` y regenerar
el índice. Y hay que borrarlo de **todos** los entornos (`var/cache/dev/`, `var/cache/prod/`):
el back-office y el front pueden correr en entornos distintos.

El nombre de la clase del autoloader cambia con la versión y hay que probar las dos:

```php
'PrestaShop\\Autoload\\PrestashopAutoload'   // PS 8.2 y 9.x — "s" minuscula en "shop"
'PrestaShopAutoload'                         // PS 1.7, 8.0 y 8.1 — namespace global
```

---

## Comprobaciones antes de entregar

```
find ecom_aftermigration -name "*.php" -print0 | xargs -0 -n1 php-7.4 -l
find ecom_aftermigration -name "*.php" -print0 | xargs -0 -n1 php-8.1 -l
find ecom_aftermigration -name "*.php" -print0 | xargs -0 -n1 php-8.3 -l
php pruebas/test_overrides.php
python pruebas/probar_pantalla.py 8.2b
python C:\dev-tools\ortografia\revisar_ortografia.py ecom_aftermigration
python herramientas/hacer_zip.py
```

`php -l` con **7.4** no es opcional. Hay construcciones que el 8.3 acepta y el 7.4 no
—`array_unshift((array) $x, ...)` es un fatal de compilación en 7.4 y en 8 no—, y el
cliente puede estar en 7.4.

Y con la tienda delante: abrir la configuración, pulsar **cada** botón, comprobar que la
casilla guardada sigue marcada al recargar, y mirar el cuerpo de cualquier respuesta 5xx.
Un formulario no está probado hasta que lo envía un navegador: una prueba por HTTP que
rellena los campos a mano manda lo que decide quien la escribe, no lo que manda el
navegador.
