# ecom_aftermigration — arreglos posteriores a una actualización o migración

Caja de herramientas para reparar lo que una actualización o una migración de
PrestaShop deja roto. Cubre PrestaShop **1.7.6 → 9.x** y **PHP 7.4 → 8.3**.

## Qué hay en esta carpeta

```
ecom_aftermigration/     EL MÓDULO. Es lo único que se comprime y se sube a la tienda.
  override_v8/           Los dos overrides, listos para copiar a override/
  sql/                   Copia de los .sql de abajo, para que viajen con el módulo
scripts/                 Guiones de línea de órdenes. NO necesitan el módulo instalado
sql/                     SQL suelto, para phpMyAdmin o Adminer
diffs/                   Parches del núcleo, uno por versión de PrestaShop
pruebas/                 Prueba automática que reproduce los fallos y verifica el arreglo
```

Los manuales están en `../manuales/ecom_aftermigration/`.

## Los tres fallos que resuelve

### 1. Fatal al validar un pedido

```
Fatal error: array_key_exists(): Argument #2 ($array) must be of type array, null given
in classes/AddressFormat.php:446
#0 classes/pdf/HTMLTemplateInvoice.php(167): AddressFormatCore::generateAddress()
#2 classes/PaymentModule.php(621): PDFCore->render()
```

Falta la clave `PS_INVCE_INVOICE_ADDR_RULES` (o la de entrega). El cliente no puede
terminar la compra.

* Arreglo de datos: `sql/01-reglas-direccion-pdf.sql` o el botón **Arreglar** del módulo.
* Red de seguridad: `override/classes/AddressFormat.php`.

### 2. Pedidos > Facturas caída

```
Expected argument of type "object, array or empty", "string" given
PropertyPathMapper->mapDataToForms()
InvoicesController->indexAction() línea 57
```

`PS_INVOICE_PREFIX` (u otra clave multiidioma) se ha quedado sin filas en
`ps_configuration_lang` y ha dejado de considerarse multiidioma.

* Arreglo: `sql/02-configuration-lang-multiidioma.sql` o el botón del módulo.

### 3. Fatal al guardar desde un módulo de terceros

```
TypeError: strip_tags(): Argument #1 ($string) must be of type string, array given
in classes/db/Db.php:795
```

Un módulo manda un array a `pSQL()`. En PHP 7 se guardaba vacío sin avisar; en PHP 8
es un fatal.

* Red de seguridad: `override/classes/db/Db.php`, que además apunta en
  `logs/escape.log` **el fichero y la línea del módulo culpable**.

## Por dónde empezar

### Con el módulo (lo normal)

1. Subir **`ecom_aftermigration.zip`** (~57 KB, 37 ficheros). `hacer_zip.py` lo deja en los
   dos sitios, con el mismo contenido: en esta carpeta y en `modulos IA/` al lado. Si te
   encuentras uno de **177 KB**, ése es la carpeta de trabajo comprimida a mano y NO vale.
2. Abrir su configuración: sale el diagnóstico con un botón **Arreglar** por fallo.
3. Pulsar **Instalar los overrides**.

**No comprimas la carpeta a mano.** La carpeta de trabajo y el módulo se llaman igual, así
que el ZIP saldría con `ecom_aftermigration/ecom_aftermigration/…` y PrestaShop responde
*«Este archivo no es un módulo en formato ZIP válido»*. Para rehacerlo tras un cambio:

```
python herramientas/hacer_zip.py
```

### Si la tienda sigue diciendo que el ZIP no vale

Pasa el comprobador **al fichero que estás subiendo**, sea el que sea:

```
php herramientas/comprobar_zip.php C:\ruta\del\fichero.zip
```

Reproduce los dos validadores del núcleo tal cual: la expresión
`/^(.*)\/\1\.php$/i` de `ZipSourceHandler` (PS 8.2 y 9.x) y el de sandbox de
`ModuleZipManager` (PS 1.7.8). Si falla, dice cuál de las tres causas es.

`ecom_aftermigration.zip` está comprobado subiéndolo de verdad por
*Módulos > Subir un módulo* en una PrestaShop 8.2.7: el núcleo contesta
`{"status": true, "msg": "Se ha instalado correctamente el módulo ecom_aftermigration."}`.
Se puede repetir con `python pruebas/probar_subida_zip.py 8.2b`.

### Y si no hay forma

Sube por FTP la carpeta `ecom_aftermigration/` (la de dentro, la que tiene
`ecom_aftermigration.php` en su raíz) a `/modules/` de la tienda, y luego instálalo desde
*Módulos*. Salta el validador del ZIP por completo.

### Sin el módulo (tienda que no arranca)

Los guiones se conectan a la base de datos leyendo `app/config/parameters.php`, sin
arrancar PrestaShop:

```
php scripts/diagnostico.php /ruta/de/la/tienda
php scripts/arreglar.php    /ruta/de/la/tienda              # simulación
php scripts/arreglar.php    /ruta/de/la/tienda --aplicar
php scripts/instalar-overrides.php /ruta/de/la/tienda
```

### A mano

`sql/00-diagnostico.sql` primero (solo lectura), luego `01` y `02`. El `03` borra: va
aparte a propósito y hay que mirarlo antes.

## Antes de tocar nada

* **Copia de la base de datos.** El `arreglar.php` no escribe sin `--aplicar`, pero el
  SQL sí.
* Tras cambiar configuración o copiar overrides, **vaciar la caché** (`var/cache/`).
* Comprobar que en *Parámetros avanzados > Rendimiento* los overrides **no** estén
  desactivados, o no servirán de nada.

## Pruebas automáticas

```
php pruebas/test_overrides.php          # los overrides, sin tienda
python pruebas/probar_pantalla.py 8.2b  # la pantalla, contra la tienda local
```

La primera tiene dos fases: reproduce los dos fatales con el código *verbatim* del núcleo
8.2.7 y después comprueba que con los overrides desaparecen y el dato se conserva. Verde
en PHP 7.4, 8.1 y 8.3 (en 7.4 la primera fase se salta: ahí eran avisos).

La segunda entra al back-office, pulsa **cada** botón y comprueba contra la base de datos
que lo que dice la pantalla ha pasado. Medido en la 8.2.7 limpia: **33 en verde, 0 fallos**.

## Añadir un fallo nuevo

Una clase en `ecom_aftermigration/classes/checks/` que herede de `EcomAmCheck` y se
registre en `EcomAmDiagnostico::__construct()`. El detalle, en el manual de desarrollo.
