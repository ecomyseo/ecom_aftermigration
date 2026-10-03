<?php
/**
 * Ecom Aftermigration - Registro del modulo y bandera que lee el override de Db
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Escritura de los dos ficheros de registro del modulo y del fichero de
 * configuracion que consulta el override de Db.
 *
 * El override NO puede leer Configuration: se ejecuta dentro de pSQL(), y
 * Configuration::loadConfiguration() llama a bqSQL(), o sea a pSQL(), o sea a
 * escape(). Por eso las opciones se vuelcan a un fichero de texto plano.
 */
class EcomAmLog
{
    const ESCAPE_MODO_IDIOMA = 'idioma';
    const ESCAPE_MODO_UNIR = 'unir';
    const ESCAPE_MODO_VACIAR = 'vaciar';

    const FICHERO_LOG = 'ecom_aftermigration.log';
    const FICHERO_ESCAPE = 'escape.log';
    const FICHERO_CONF = 'escape.conf';

    /** @var int tamano maximo de cada .log antes de rotarlo */
    const MAX_BYTES = 1048576;

    /**
     * @return array
     */
    public static function modosEscapeValidos()
    {
        return array(self::ESCAPE_MODO_IDIOMA, self::ESCAPE_MODO_UNIR, self::ESCAPE_MODO_VACIAR);
    }

    /**
     * @return string ruta absoluta de la carpeta de registros, con la barra final
     */
    public static function carpeta()
    {
        return _PS_MODULE_DIR_ . 'ecom_aftermigration/logs/';
    }

    /**
     * Escribe una linea en el registro del modulo. Solo si el modo de depuracion
     * esta activo, salvo que se fuerce.
     *
     * @param string $texto
     * @param bool $forzar
     */
    public static function escribir($texto, $forzar = false)
    {
        if (!$forzar && !(int) Configuration::get('ECOM_AM_DEBUG')) {
            return;
        }

        self::anadirLinea(self::carpeta() . self::FICHERO_LOG, $texto);
    }

    /**
     * @param string $fichero ruta absoluta
     * @param string $texto
     */
    private static function anadirLinea($fichero, $texto)
    {
        $carpeta = dirname($fichero);
        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0755, true);
        }
        if (!is_dir($carpeta)) {
            return;
        }

        if (file_exists($fichero) && filesize($fichero) > self::MAX_BYTES) {
            @rename($fichero, $fichero . '.1');
        }

        @file_put_contents(
            $fichero,
            date('Y-m-d H:i:s') . ' | ' . $texto . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Vuelca las opciones a logs/escape.conf, que es lo unico que lee el override.
     */
    public static function sincronizarBandera()
    {
        $carpeta = self::carpeta();
        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0755, true);
        }
        if (!is_dir($carpeta)) {
            return;
        }

        $modo = (string) Configuration::get('ECOM_AM_ESCAPE_MODO');
        if (!in_array($modo, self::modosEscapeValidos(), true)) {
            $modo = self::ESCAPE_MODO_IDIOMA;
        }

        $contenido = 'modo=' . $modo . "\n"
            . 'log=' . ((int) Configuration::get('ECOM_AM_ESCAPE_LOG') ? '1' : '0') . "\n"
            . 'id_lang=' . (int) Configuration::get('PS_LANG_DEFAULT') . "\n";

        @file_put_contents($carpeta . self::FICHERO_CONF, $contenido, LOCK_EX);

        self::protegerCarpeta();
    }

    /**
     * Deja un .htaccess y un index.php en logs/ para que no se pueda leer por URL.
     */
    public static function protegerCarpeta()
    {
        $carpeta = self::carpeta();
        if (!is_dir($carpeta)) {
            return;
        }

        if (!file_exists($carpeta . 'index.php')) {
            @file_put_contents($carpeta . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }

        if (!file_exists($carpeta . '.htaccess')) {
            $htaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
            @file_put_contents($carpeta . '.htaccess', $htaccess);
        }
    }

    public static function quitarBandera()
    {
        $fichero = self::carpeta() . self::FICHERO_CONF;
        if (file_exists($fichero)) {
            @unlink($fichero);
        }
    }

    public static function vaciar()
    {
        foreach (array(self::FICHERO_LOG, self::FICHERO_ESCAPE) as $nombre) {
            $fichero = self::carpeta() . $nombre;
            if (file_exists($fichero)) {
                @file_put_contents($fichero, '');
            }
        }
    }

    /**
     * @param int $cuantas
     *
     * @return array
     */
    public static function ultimasLineas($cuantas = 40)
    {
        return self::leerCola(self::carpeta() . self::FICHERO_LOG, $cuantas);
    }

    /**
     * @param int $cuantas
     *
     * @return array
     */
    public static function ultimasLineasEscape($cuantas = 40)
    {
        return self::leerCola(self::carpeta() . self::FICHERO_ESCAPE, $cuantas);
    }

    /**
     * @param string $fichero
     * @param int $cuantas
     *
     * @return array
     */
    private static function leerCola($fichero, $cuantas)
    {
        if (!file_exists($fichero) || !is_readable($fichero)) {
            return array();
        }

        $lineas = @file($fichero, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lineas) || !count($lineas)) {
            return array();
        }

        return array_reverse(array_slice($lineas, -1 * (int) $cuantas));
    }

    /**
     * @return int numero de incidencias registradas por el override de Db
     */
    public static function contarIncidenciasEscape()
    {
        $fichero = self::carpeta() . self::FICHERO_ESCAPE;
        if (!file_exists($fichero)) {
            return 0;
        }

        $lineas = @file($fichero, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lineas) ? count($lineas) : 0;
    }
}
