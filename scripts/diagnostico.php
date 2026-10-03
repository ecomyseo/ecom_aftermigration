<?php
/**
 * Ecom Aftermigration - Diagnostico de linea de ordenes (NO escribe nada)
 *
 * Uso:
 *     php diagnostico.php /ruta/de/la/tienda
 *
 * Si se lanza desde dentro de la tienda, la ruta se puede omitir.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
require_once __DIR__ . '/comun.php';

$raiz = ecomamRaizTienda($argv);
$credenciales = ecomamCredenciales($raiz);
$pdo = ecomamConectar($credenciales);
$pre = $credenciales['prefijo'];

echo 'Tienda: ' . $raiz . PHP_EOL;
echo 'Base:   ' . $credenciales['base'] . ' (prefijo ' . $pre . ')' . PHP_EOL;
echo 'PHP:    ' . PHP_VERSION . PHP_EOL;

$fallos = 0;

// ---------------------------------------------------------------- idiomas
$idiomas = array();
foreach ($pdo->query('SELECT `id_lang`, `iso_code`, `active` FROM `' . $pre . 'lang` ORDER BY `id_lang`') as $fila) {
    $idiomas[(int) $fila['id_lang']] = $fila['iso_code'];
}
echo 'Idiomas: ' . implode(', ', array_map(function ($id, $iso) {
    return $id . '=' . $iso;
}, array_keys($idiomas), $idiomas)) . PHP_EOL;

// ------------------------------------------- 1) reglas de direccion de los PDF
ecomamTitulo('1. Reglas de dirección de los PDF (fatal al validar un pedido)');

$claves = array('PS_INVCE_INVOICE_ADDR_RULES', 'PS_INVCE_DELIVERY_ADDR_RULES');
foreach ($claves as $clave) {
    $consulta = $pdo->prepare(
        'SELECT `id_configuration`, `id_shop`, `value` FROM `' . $pre . 'configuration` WHERE `name` = ?'
    );
    $consulta->execute(array($clave));
    $filas = $consulta->fetchAll();

    if (!count($filas)) {
        echo '  FALLO  ' . $clave . ': no existe ninguna fila.' . PHP_EOL;
        ++$fallos;
        continue;
    }

    foreach ($filas as $fila) {
        $decodificado = json_decode((string) $fila['value'], true);
        if (is_array($decodificado)) {
            echo '  OK     ' . $clave . ' (id_shop ' . (int) $fila['id_shop'] . ')' . PHP_EOL;
        } else {
            echo '  FALLO  ' . $clave . ' (id_shop ' . (int) $fila['id_shop'] . '): '
                . var_export($fila['value'], true) . PHP_EOL;
            ++$fallos;
        }
    }
}

// ------------------------------------------------------ 2) configuration_lang
ecomamTitulo('2. Claves multiidioma sin filas en configuration_lang (tumba Pedidos > Facturas)');

$detectadas = array();
foreach ($pdo->query(
    'SELECT DISTINCT c.`name`
     FROM `' . $pre . 'configuration` c
     INNER JOIN `' . $pre . 'configuration_lang` cl ON cl.`id_configuration` = c.`id_configuration`'
) as $fila) {
    $detectadas[] = $fila['name'];
}

$todas = array_values(array_unique(array_merge(ecomamClavesMultiidioma(), $detectadas)));
$marcas = implode(',', array_fill(0, count($todas), '?'));

$consulta = $pdo->prepare(
    'SELECT c.`id_configuration`, c.`name`, c.`id_shop`,
            (SELECT COUNT(*) FROM `' . $pre . 'configuration_lang` cl
             WHERE cl.`id_configuration` = c.`id_configuration`) AS filas_lang
     FROM `' . $pre . 'configuration` c
     WHERE c.`name` IN (' . $marcas . ')
     ORDER BY c.`name`'
);
$consulta->execute($todas);

$faltan = 0;
foreach ($consulta->fetchAll() as $fila) {
    $tiene = (int) $fila['filas_lang'];
    $deberia = count($idiomas);

    if ($tiene >= $deberia) {
        continue;
    }

    ++$faltan;
    echo '  FALLO  ' . $fila['name'] . ' (id_shop ' . (int) $fila['id_shop'] . '): '
        . $tiene . ' de ' . $deberia . ' idiomas.' . PHP_EOL;
}

if (!$faltan) {
    echo '  OK     Todas las claves multiidioma tienen su fila por idioma.' . PHP_EOL;
} else {
    $fallos += $faltan;
}

$huerfanas = (int) $pdo->query(
    'SELECT COUNT(*) FROM `' . $pre . 'configuration_lang` cl
     LEFT JOIN `' . $pre . 'configuration` c ON c.`id_configuration` = cl.`id_configuration`
     WHERE c.`id_configuration` IS NULL'
)->fetchColumn();

if ($huerfanas) {
    echo '  AVISO  ' . $huerfanas . ' fila(s) de configuration_lang sin su fila en configuration.' . PHP_EOL;
}

// ------------------------------------------------------- 3) tablas _lang cojas
ecomamTitulo('3. Tablas _lang con filas que faltan');

$tablas = array();
foreach ($pdo->query(
    "SELECT DISTINCT s.TABLE_NAME
     FROM INFORMATION_SCHEMA.STATISTICS s
     WHERE s.TABLE_SCHEMA = DATABASE()
       AND s.INDEX_NAME = 'PRIMARY'
       AND s.COLUMN_NAME = 'id_lang'
     ORDER BY s.TABLE_NAME"
) as $fila) {
    $nombre = $fila['TABLE_NAME'];
    if (strpos($nombre, $pre) !== 0) {
        continue;
    }
    if (substr($nombre, -5) !== '_lang') {
        continue;
    }
    $tablas[] = $nombre;
}

$cojas = 0;
foreach ($tablas as $tabla) {
    $porIdioma = array();
    foreach ($pdo->query('SELECT `id_lang`, COUNT(*) AS n FROM `' . $tabla . '` GROUP BY `id_lang`') as $fila) {
        $porIdioma[(int) $fila['id_lang']] = (int) $fila['n'];
    }

    $maximo = 0;
    foreach ($idiomas as $idLang => $iso) {
        $n = isset($porIdioma[$idLang]) ? $porIdioma[$idLang] : 0;
        if ($n > $maximo) {
            $maximo = $n;
        }
    }

    if (!$maximo) {
        continue;
    }

    $trozos = array();
    foreach ($idiomas as $idLang => $iso) {
        $n = isset($porIdioma[$idLang]) ? $porIdioma[$idLang] : 0;
        if ($n < $maximo) {
            $trozos[] = $iso . ' (id_lang ' . $idLang . '): faltan ' . ($maximo - $n);
        }
    }

    $muertos = 0;
    foreach ($porIdioma as $idLang => $n) {
        if (!isset($idiomas[$idLang])) {
            $muertos += $n;
        }
    }

    if (count($trozos)) {
        ++$cojas;
        echo '  FALLO  ' . $tabla . ': ' . implode(' | ', $trozos) . PHP_EOL;
    }
    if ($muertos) {
        echo '  AVISO  ' . $tabla . ': ' . $muertos . ' fila(s) de un idioma que ya no existe.' . PHP_EOL;
    }
}

if (!$cojas) {
    echo '  OK     Las ' . count($tablas) . ' tablas _lang tienen las mismas filas en todos los idiomas.' . PHP_EOL;
} else {
    $fallos += $cojas;
}

// ------------------------------------------------------------- 4) overrides
ecomamTitulo('4. Overrides de protección');

$destinos = array(
    'override/classes/db/Db.php',
    'override/classes/AddressFormat.php',
);

foreach ($destinos as $relativo) {
    $ruta = $raiz . '/' . $relativo;
    if (!is_file($ruta)) {
        echo '  AVISO  ' . $relativo . ': sin instalar.' . PHP_EOL;
        continue;
    }

    $contenido = (string) file_get_contents($ruta);
    if (strpos($contenido, 'ECOM_AFTERMIGRATION_OVERRIDE') !== false) {
        echo '  OK     ' . $relativo . ': instalado.' . PHP_EOL;
    } else {
        echo '  AVISO  ' . $relativo . ': hay otro override puesto, hay que fusionarlo a mano.' . PHP_EOL;
    }
}

$desactivados = $pdo->prepare(
    'SELECT `value` FROM `' . $pre . "configuration` WHERE `name` = 'PS_DISABLE_OVERRIDES'"
);
$desactivados->execute();
if ((int) $desactivados->fetchColumn() === 1) {
    echo '  FALLO  PS_DISABLE_OVERRIDES esta a 1: PrestaShop ignora todos los overrides.' . PHP_EOL;
    ++$fallos;
}

// ----------------------------------------------------------------- resumen
ecomamTitulo('Resumen');
echo 'FALLAN: ' . $fallos . PHP_EOL;
echo 'Para arreglarlo: php ' . basename(__DIR__) . '/arreglar.php ' . $raiz . ' --aplicar' . PHP_EOL;

exit($fallos > 0 ? 1 : 0);
