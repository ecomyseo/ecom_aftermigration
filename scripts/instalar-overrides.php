<?php
/**
 * Ecom Aftermigration - Copia los dos overrides sin instalar el modulo
 *
 * Uso:
 *     php instalar-overrides.php /ruta/de/la/tienda
 *     php instalar-overrides.php /ruta/de/la/tienda --quitar
 *
 * No pisa nunca un override que no lleve la marca ECOM_AFTERMIGRATION_OVERRIDE:
 * si ya hay uno de otro modulo, lo dice y para, para que se fusione a mano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
require_once __DIR__ . '/comun.php';

$raiz = ecomamRaizTienda($argv);
$quitar = ecomamTiene($argv, '--quitar');
$marca = 'ECOM_AFTERMIGRATION_OVERRIDE';

$origen = dirname(__DIR__) . '/ecom_aftermigration/override_v8/';
$destino = $raiz . '/override/';

$ficheros = array(
    'classes/db/Db.php',
    'classes/AddressFormat.php',
);

echo 'Tienda: ' . $raiz . PHP_EOL;
echo 'Origen: ' . $origen . PHP_EOL;

$hechos = 0;

foreach ($ficheros as $relativo) {
    $rutaOrigen = $origen . $relativo;
    $rutaDestino = $destino . $relativo;

    if ($quitar) {
        if (!is_file($rutaDestino)) {
            echo '  ' . $relativo . ': no estaba.' . PHP_EOL;
            continue;
        }

        if (strpos((string) file_get_contents($rutaDestino), $marca) === false) {
            echo '  ' . $relativo . ': NO es nuestro, no se toca.' . PHP_EOL;
            continue;
        }

        if (unlink($rutaDestino)) {
            echo '  ' . $relativo . ': borrado.' . PHP_EOL;
            ++$hechos;
        } else {
            echo '  ' . $relativo . ': no se ha podido borrar.' . PHP_EOL;
        }

        continue;
    }

    if (!is_file($rutaOrigen)) {
        echo '  ' . $relativo . ': no esta en el paquete.' . PHP_EOL;
        continue;
    }

    if (is_file($rutaDestino)) {
        $contenido = (string) file_get_contents($rutaDestino);
        if (strpos($contenido, $marca) === false) {
            echo '  ' . $relativo . ': YA HAY OTRO OVERRIDE. Hay que fusionarlo a mano, no se toca.' . PHP_EOL;
            continue;
        }
        copy($rutaDestino, $rutaDestino . '.bak-' . date('Ymd-His'));
    }

    $carpeta = dirname($rutaDestino);
    if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true)) {
        echo '  ' . $relativo . ': no se ha podido crear ' . $carpeta . PHP_EOL;
        continue;
    }

    $indice = $carpeta . '/index.php';
    if (!is_file($indice)) {
        file_put_contents($indice, "<?php\nheader('Location: ../');\nexit;\n");
    }

    if (copy($rutaOrigen, $rutaDestino)) {
        echo '  ' . $relativo . ': copiado.' . PHP_EOL;
        ++$hechos;
    } else {
        echo '  ' . $relativo . ': no se ha podido copiar. Revisa los permisos.' . PHP_EOL;
    }
}

// El indice de clases es lo que decide si un override se carga.
foreach (array('/var/cache/class_index.php', '/cache/class_index.php') as $relativo) {
    $indice = $raiz . $relativo;
    if (is_file($indice) && unlink($indice)) {
        echo 'Borrado ' . $relativo . PHP_EOL;
    }
}

foreach (glob($raiz . '/var/cache/*/class_index.php') as $indice) {
    if (unlink($indice)) {
        echo 'Borrado ' . str_replace($raiz, '', $indice) . PHP_EOL;
    }
}

echo PHP_EOL . 'Ficheros tocados: ' . $hechos . PHP_EOL;
echo 'Vacia la cache del back-office (Parámetros avanzados > Rendimiento) y comprueba' . PHP_EOL;
echo 'que ahi los overrides NO esten desactivados.' . PHP_EOL;
