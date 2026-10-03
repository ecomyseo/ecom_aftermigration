<?php
/**
 * Ecom Aftermigration - Utilidades comunes de los guiones de linea de ordenes
 *
 * Se conecta a la base de datos SIN arrancar PrestaShop, leyendo las credenciales
 * de app/config/parameters.php (1.7, 8.x y 9.x) o de config/settings.inc.php (1.6).
 *
 * Es a proposito: una tienda recien migrada puede no arrancar, y estos guiones
 * tienen que funcionar precisamente ahi.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (PHP_SAPI !== 'cli') {
    header('Location: ../');
    exit;
}

/**
 * Localiza la raiz de la tienda: el argumento, la carpeta actual o hacia arriba.
 *
 * @param array $argv
 *
 * @return string
 */
function ecomamRaizTienda(array $argv)
{
    $candidatas = array();

    foreach ($argv as $indice => $valor) {
        if ($indice > 0 && substr($valor, 0, 2) !== '--') {
            $candidatas[] = rtrim($valor, "/\\");
        }
    }

    $candidatas[] = getcwd();
    $candidatas[] = dirname(__DIR__);

    $subiendo = __DIR__;
    for ($i = 0; $i < 6; ++$i) {
        $subiendo = dirname($subiendo);
        $candidatas[] = $subiendo;
    }

    foreach ($candidatas as $carpeta) {
        if ($carpeta && is_file($carpeta . '/config/config.inc.php')) {
            return $carpeta;
        }
    }

    fwrite(STDERR, "No encuentro la raiz de la tienda.\n");
    fwrite(STDERR, "Uso: php " . basename($argv[0]) . " /ruta/de/la/tienda\n");
    exit(1);
}

/**
 * @param string $raiz
 *
 * @return array host, puerto, base, usuario, clave, prefijo
 */
function ecomamCredenciales($raiz)
{
    $parametros = $raiz . '/app/config/parameters.php';

    if (is_file($parametros)) {
        $datos = include $parametros;
        if (is_array($datos) && isset($datos['parameters'])) {
            $p = $datos['parameters'];
            $host = isset($p['database_host']) ? $p['database_host'] : 'localhost';
            $puerto = isset($p['database_port']) ? (string) $p['database_port'] : '';

            if (strpos($host, ':') !== false) {
                $trozos = explode(':', $host, 2);
                $host = $trozos[0];
                if ($puerto === '') {
                    $puerto = $trozos[1];
                }
            }

            return array(
                'host' => $host,
                'puerto' => $puerto === '' ? '3306' : $puerto,
                'base' => isset($p['database_name']) ? $p['database_name'] : '',
                'usuario' => isset($p['database_user']) ? $p['database_user'] : '',
                'clave' => isset($p['database_password']) ? $p['database_password'] : '',
                'prefijo' => isset($p['database_prefix']) ? $p['database_prefix'] : 'ps_',
            );
        }
    }

    $ajustes = $raiz . '/config/settings.inc.php';
    if (is_file($ajustes)) {
        $texto = (string) file_get_contents($ajustes);
        $sacar = function ($constante) use ($texto) {
            if (preg_match("/define\(\s*'" . $constante . "'\s*,\s*'([^']*)'/", $texto, $m)) {
                return $m[1];
            }

            return '';
        };

        $host = $sacar('_DB_SERVER_');
        $puerto = '3306';
        if (strpos($host, ':') !== false) {
            $trozos = explode(':', $host, 2);
            $host = $trozos[0];
            $puerto = $trozos[1];
        }

        return array(
            'host' => $host,
            'puerto' => $puerto,
            'base' => $sacar('_DB_NAME_'),
            'usuario' => $sacar('_DB_USER_'),
            'clave' => $sacar('_DB_PASSWD_'),
            'prefijo' => $sacar('_DB_PREFIX_'),
        );
    }

    fwrite(STDERR, "No encuentro ni app/config/parameters.php ni config/settings.inc.php en $raiz\n");
    exit(1);
}

/**
 * @param array $credenciales
 *
 * @return PDO
 */
function ecomamConectar(array $credenciales)
{
    $dsn = 'mysql:host=' . $credenciales['host']
        . ';port=' . $credenciales['puerto']
        . ';dbname=' . $credenciales['base']
        . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, $credenciales['usuario'], $credenciales['clave'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    } catch (PDOException $e) {
        fwrite(STDERR, 'No se ha podido conectar: ' . $e->getMessage() . "\n");
        exit(1);
    }

    return $pdo;
}

/**
 * @param string $texto
 */
function ecomamTitulo($texto)
{
    echo PHP_EOL . $texto . PHP_EOL . str_repeat('-', strlen($texto)) . PHP_EOL;
}

/**
 * @param array $argv
 * @param string $bandera
 *
 * @return bool
 */
function ecomamTiene(array $argv, $bandera)
{
    return in_array($bandera, $argv, true);
}

/**
 * Claves de configuracion que el nucleo trata como multiidioma.
 *
 * @return array
 */
function ecomamClavesMultiidioma()
{
    return array(
        'PS_INVOICE_PREFIX',
        'PS_DELIVERY_PREFIX',
        'PS_RETURN_PREFIX',
        'PS_CREDIT_SLIP_PREFIX',
        'PS_INVOICE_LEGAL_FREE_TEXT',
        'PS_INVOICE_FREE_TEXT',
        'PS_SEARCH_BLACKLIST',
        'PS_CUSTOMER_SERVICE_SIGNATURE',
        'PS_MAINTENANCE_TEXT',
        'PS_LABEL_IN_STOCK_PRODUCTS',
        'PS_LABEL_OOS_PRODUCTS_BOA',
        'PS_LABEL_OOS_PRODUCTS_BOD',
    );
}
