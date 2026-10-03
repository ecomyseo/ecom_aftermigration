# Arreglos posteriores a una migración — guía de uso

Este módulo revisa la tienda después de una actualización o una migración de PrestaShop,
te dice qué se ha quedado roto y lo arregla con un botón.

---

## Para qué sirve

Cuando una tienda se actualiza o se mueve de servidor, hay cosas que se quedan por el
camino sin que nadie se entere, y aparecen días después. Las cuatro más habituales:

**No se pueden terminar los pedidos.** El cliente paga y, en vez de la página de gracias,
sale una pantalla de error. Le falta a la tienda un ajuste de la factura en PDF.

**La pantalla de facturas no abre.** *Pedidos > Facturas* sale en blanco o con un error
largo en inglés. Se ha perdido el prefijo de factura en alguno de los idiomas.

**Productos o categorías en blanco en un idioma.** La ficha está bien en español y vacía
en inglés, por ejemplo.

**Pantallas que se caen al guardar algo desde otro módulo.** Un módulo de terceros manda
un dato en un formato que el PHP nuevo ya no acepta.

---

## Instalarlo

1. Sube el ZIP del módulo desde *Módulos > Subir un módulo*.
2. Instálalo y pulsa **Configurar**.

Nada más instalarlo **no cambia nada en la tienda**. Solo mira.

---

## Usarlo

Al abrir la configuración sale una tabla con una fila por revisión y su estado:

| Estado | Qué significa |
|---|---|
| **Bien** | no hay nada que hacer |
| **Aviso** | hay algo que conviene mirar, pero no rompe nada |
| **Fallo** | hay algo roto ahora mismo |

Cada fila con un problema trae un botón **Arreglar**. Pulsa uno, espera, y la tabla se
vuelve a pintar con el resultado.

**Antes de pulsar nada, pídele a tu servicio de alojamiento una copia de seguridad de la
base de datos.** Es un minuto y te deja dormir tranquilo.

Debajo de la tabla hay cuatro botones:

* **Volver a comprobar** — vuelve a pasar todas las revisiones.
* **Instalar los overrides** — pone los dos ficheros de protección. Esto es lo que evita
  que la tienda se caiga del todo si el problema vuelve a aparecer. **Púlsalo.**
* **Quitar los overrides** — los retira. Solo si te lo pide alguien de soporte.
* **Vaciar los registros** — borra el historial de abajo.

Y un desplegable **Ayuda / cómo funciona** con la explicación larga de cada revisión.

---

## Qué revisa, en cristiano

### Reglas de dirección del PDF de la factura

Es la que deja a los clientes sin poder comprar. Si sale en rojo, arréglala **la primera**.

### Claves de configuración multiidioma

La que tumba *Pedidos > Facturas* y también Albaranes, Facturas por abono, Devoluciones,
Preferencias de producto y Mantenimiento. El arreglo repone el texto que falta copiándolo
del idioma que sí lo tiene.

### Filas que faltan en las tablas de idioma

Productos, categorías o páginas que están completos en un idioma y vacíos en otro. El
arreglo copia el contenido del idioma que más tiene, así que **quedan en el idioma
original hasta que alguien los traduzca**. Es mejor que una ficha en blanco, pero no es
una traducción: revisa después lo que te importe.

Este arreglo **nunca borra nada** y nunca pisa lo que ya esté traducido.

### Overrides de protección

Dice si los dos ficheros de seguridad están puestos. Si sale en rojo, pulsa **Instalar los
overrides**.

Si te dice que *los overrides están desactivados*, ve a *Parámetros avanzados >
Rendimiento* y actívalos, o no servirán de nada.

### Arrays que llegan a pSQL()

Es un diagnóstico, no un arreglo: **aquí no hay botón**. Cada línea es otro módulo de la
tienda guardando mal un dato. El módulo apunta el fichero y la línea exactos. Pásale esa
lista a quien mantiene ese módulo: es lo que necesita para arreglarlo en dos minutos.

Mientras tanto, la tienda no se cae.

---

## La pestaña de ajustes

Debajo del diagnóstico hay un formulario con dos pestañas. Los valores de fábrica están
bien; solo tienes que tocarlos si te lo pide soporte.

**Arreglos**

* *Array que llega a pSQL()* — qué hacer con el dato mal formado. Por defecto se queda con
  el idioma principal de la tienda, que es lo que salva el texto casi siempre.
* *Tope de filas por tabla de idioma* — las tablas más grandes que esto solo se informan,
  no se arreglan solas, para no tener la tienda bloqueada un cuarto de hora.

**Registros**

* *Registrar los arrays que caza el override* — déjalo activado: es lo que te dice qué
  módulo hay que arreglar.
* *Modo de depuración* — solo para diagnosticar un problema concreto.

---

## Después de arreglar

Vacía la caché en *Parámetros avanzados > Rendimiento* y comprueba lo que estaba roto:

* haz un pedido de prueba y llega hasta el final;
* abre *Pedidos > Facturas*;
* mira una ficha de producto en cada idioma.

---

## Preguntas frecuentes

**¿Puedo desinstalarlo cuando termine?**
Sí. Los arreglos ya hechos se quedan, y los overrides también. Aunque es mejor dejarlo
puesto: la próxima actualización vuelve a romper algo.

**¿Toca el diseño o los precios?**
No. Solo ajustes de configuración y textos de idioma que faltan.

**¿Y si el arreglo no funciona?**
El módulo vuelve a comprobarlo antes de decirte que sí, así que si dice que ha fallado, ha
fallado de verdad. Activa el *Modo de depuración*, vuelve a pulsar, y manda a soporte el
fichero `modules/ecom_aftermigration/logs/ecom_aftermigration.log`.

**Mi tienda no arranca, no puedo ni entrar al back-office.**
Entonces no puedes instalar el módulo. Pásale a tu técnico la carpeta `scripts/` del
paquete: trae guiones que se conectan directamente a la base de datos y hacen lo mismo.
