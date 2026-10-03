<?php
/**
 * Ecom Aftermigration - Arreglo de linea de ordenes
 *
 * Por defecto NO escribe: ensena lo que haria. Para aplicarlo hay que pasar
 * --aplicar de forma explicita.
 *
 * Uso:
 *     php arreglar.php /ruta/de/la/tienda              (simulacion)
 *     php arreglar.php /ruta/de/la/tienda --aplicar    (escribe)
 *     php arreglar.php /ruta/de/la/tienda --aplicar --tablas-lang
 *
 * --tablas-lang tambien copia las filas que faltan en las tablas _lang. Se deja
 * aparte porque en un catalogo grande es la operacion mas pesada.
 *
 * Nunca borra nada. Los borrados estan en sql/03-limpiar-filas-huerfanas.sql,
 * para ejecutarlos a mano despues de mirarlos.
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

$aplicar = ecomamTiene($argv, '--aplicar');
$conTablas = ecomamTiene($argv, '--tablas-lang');

echo 'Tienda: ' . $raiz . PHP_EOL;
echo 'Base:   ' . $credenciales['base'] . ' (prefijo ' . $pre . ')' . PHP_EOL;
echo 'Modo:   ' . ($aplicar ? 'APLICANDO CAMBIOS' : 'simulacion, no se escribe nada') . PHP_EOL;

if ($aplicar) {
    echo PHP_EOL . 'Haz una copia de la base de datos antes de seguir.' . PHP_EOL;
}

$idiomas = array();
foreach ($pdo->query('SELECT `id_lang` FROM `' . $pre . 'lang` ORDER BY `id_lang`') as $fila) {
    $idiomas[] = (int) $fila['id_lang'];
}

$idiomaPorDefecto = (int) $pdo->query(
    'SELECT `value` FROM `' . $pre . "configuration` WHERE `name` = 'PS_LANG_DEFAULT'"
)->fetchColumn();

if (!$idiomaPorDefecto && count($idiomas)) {
    $idiomaPorDefecto = $idiomas[0];
}

$hechas = 0;

// --------------------------------------- 1) reglas de direccion de los PDF
ecomamTitulo('1. Reglas de dirección de los PDF');

foreach (array('PS_INVCE_INVOICE_ADDR_RULES', 'PS_INVCE_DELIVERY_ADDR_RULES') as $clave) {
    $consulta = $pdo->prepare(
        'SELECT `id_configuration`, `id_shop`, `value` FROM `' . $pre . 'configuration` WHERE `name` = ?'
    );
    $consulta->execute(array($clave));
    $filas = $consulta->fetchAll();

    if (!count($filas)) {
        echo '  crear fila global ' . $clave . ' = {"avoid":[]}' . PHP_EOL;
        if ($aplicar) {
            $insertar = $pdo->prepare(
                'INSERT INTO `' . $pre . 'configuration`
                 (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`)
                 VALUES (NULL, NULL, ?, ?, NOW(), NOW())'
            );
            $insertar->execute(array($clave, '{"avoid":[]}'));
        }
        ++$hechas;
        continue;
    }

    foreach ($filas as $fila) {
        if (is_array(json_decode((string) $fila['value'], true))) {
            continue;
        }

        echo '  reponer ' . $clave . ' (id_configuration ' . (int) $fila['id_configuration'] . ')' . PHP_EOL;
        if ($aplicar) {
            $actualizar = $pdo->prepare(
                'UPDATE `' . $pre . 'configuration` SET `value` = ?, `date_upd` = NOW() WHERE `id_configuration` = ?'
            );
            $actualizar->execute(array('{"avoid":[]}', (int) $fila['id_configuration']));
        }
        ++$hechas;
    }
}

// -------------------------------------------------------- 2) configuration_lang
ecomamTitulo('2. Filas que faltan en configuration_lang');

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
    'SELECT `id_configuration`, `name`, `id_shop`, `value`
     FROM `' . $pre . 'configuration`
     WHERE `name` IN (' . $marcas . ')
     ORDER BY `name`'
);
$consulta->execute($todas);
$claves = $consulta->fetchAll();

$leerLang = $pdo->prepare(
    'SELECT `id_lang`, `value` FROM `' . $pre . 'configuration_lang` WHERE `id_configuration` = ?'
);
$insertarLang = $pdo->prepare(
    'INSERT IGNORE INTO `' . $pre . 'configuration_lang` (`id_configuration`, `id_lang`, `value`, `date_upd`)
     VALUES (?, ?, ?, NOW())'
);

$insertadas = 0;
foreach ($claves as $clave) {
    $leerLang->execute(array((int) $clave['id_configuration']));
    $tiene = array();
    foreach ($leerLang->fetchAll() as $fila) {
        $tiene[(int) $fila['id_lang']] = $fila['value'];
    }

    $referencia = '';
    if (isset($tiene[$idiomaPorDefecto]) && $tiene[$idiomaPorDefecto] !== '') {
        $referencia = (string) $tiene[$idiomaPorDefecto];
    } else {
        foreach ($tiene as $valor) {
            if ($valor !== null && $valor !== '') {
                $referencia = (string) $valor;
                break;
            }
        }
    }
    if ($referencia === '' && $clave['value'] !== null) {
        $referencia = (string) $clave['value'];
    }

    foreach ($idiomas as $idLang) {
        if (array_key_exists($idLang, $tiene)) {
            continue;
        }

        echo '  ' . $clave['name'] . ' (id_configuration ' . (int) $clave['id_configuration']
            . ') -> id_lang ' . $idLang . ' = ' . var_export($referencia, true) . PHP_EOL;

        if ($aplicar) {
            $insertarLang->execute(array((int) $clave['id_configuration'], $idLang, $referencia));
        }
        ++$insertadas;
    }
}

if (!$insertadas) {
    echo '  Nada que hacer.' . PHP_EOL;
}
$hechas += $insertadas;

// ------------------------------------------------------------ 3) tablas _lang
ecomamTitulo('3. Filas que faltan en las tablas _lang');

if (!$conTablas) {
    echo '  Saltado. Añade --tablas-lang si también quieres esto.' . PHP_EOL;
} else {
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
        if (strpos($nombre, $pre) !== 0 || substr($nombre, -5) !== '_lang') {
            continue;
        }
        if ($nombre === $pre . 'configuration_lang') {
            continue;
        }
        $tablas[] = $nombre;
    }

    $copias = 0;
    foreach ($tablas as $tabla) {
        $porIdioma = array();
        foreach ($pdo->query('SELECT `id_lang`, COUNT(*) AS n FROM `' . $tabla . '` GROUP BY `id_lang`') as $fila) {
            $porIdioma[(int) $fila['id_lang']] = (int) $fila['n'];
        }

        $referencia = 0;
        $maximo = 0;
        foreach ($idiomas as $idLang) {
            $n = isset($porIdioma[$idLang]) ? $porIdioma[$idLang] : 0;
            if ($n > $maximo) {
                $maximo = $n;
                $referencia = $idLang;
            }
        }

        if (!$maximo) {
            continue;
        }

        $columnas = array();
        $consultaColumnas = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $consultaColumnas->execute(array($tabla));
        foreach ($consultaColumnas->fetchAll() as $fila) {
            $columnas[] = $fila['COLUMN_NAME'];
        }

        foreach ($idiomas as $idLang) {
            $n = isset($porIdioma[$idLang]) ? $porIdioma[$idLang] : 0;
            if ($n >= $maximo) {
                continue;
            }

            $lista = array();
            $seleccion = array();
            foreach ($columnas as $columna) {
                $lista[] = '`' . $columna . '`';
                $seleccion[] = $columna === 'id_lang' ? (int) $idLang : '`' . $columna . '`';
            }

            $sql = 'INSERT IGNORE INTO `' . $tabla . '` (' . implode(', ', $lista) . ')'
                . ' SELECT ' . implode(', ', $seleccion)
                . ' FROM `' . $tabla . '` WHERE `id_lang` = ' . (int) $referencia;

            echo '  ' . $tabla . ': copiar ' . ($maximo - $n) . ' fila(s) de id_lang '
                . $referencia . ' a id_lang ' . $idLang . PHP_EOL;

            if ($aplicar) {
                $pdo->exec($sql);
            }
            ++$copias;
        }
    }

    if (!$copias) {
        echo '  Nada que hacer.' . PHP_EOL;
    }
    $hechas += $copias;
}

// ----------------------------------------------------------------- resumen
ecomamTitulo('Resumen');
echo 'CAMBIOS ' . ($aplicar ? 'APLICADOS' : 'PENDIENTES') . ': ' . $hechas . PHP_EOL;

if ($aplicar && $hechas) {
    echo 'Vacia la cache de la tienda (var/cache/) y vuelve a pasar diagnostico.php.' . PHP_EOL;
}
