<?php
/**
 * ECOM_AFTERMIGRATION_OVERRIDE
 *
 * Ecom Aftermigration - Db::escape() deja de ser un fatal en PHP 8
 *
 * El nucleo hace, en classes/db/Db.php:
 *
 *     public function escape($string, $html_ok = false, $bq_sql = false)
 *     {
 *         if (!is_numeric($string)) {
 *             $string = $this->_escape($string);
 *             if (!$html_ok) {
 *                 $string = strip_tags(Tools::nl2br($string));
 *             }
 *             ...
 *
 * y DbPDOCore::_escape() resuelve con str_replace(), que si recibe un array
 * DEVUELVE UN ARRAY. En PHP 5 y 7 strip_tags() protestaba y seguia; en PHP 8 es
 * un TypeError y se lleva la peticion por delante:
 *
 *     strip_tags(): Argument #1 ($string) must be of type string, array given
 *     in classes/db/Db.php on line 795
 *
 * Llega hasta aqui cualquier modulo que guarde un ObjectModel con un campo que
 * el formulario manda como array (un campo multiidioma en una columna que no lo
 * es, un select multiple). Con PHP 7 se guardaba vacio y nadie se enteraba.
 *
 * Esto NO arregla el modulo culpable: evita el fatal, conserva el dato lo mejor
 * que puede y apunta en modules/ecom_aftermigration/logs/escape.log el fichero y
 * la linea desde donde ha llegado, que es lo que hay que corregir.
 *
 * Las opciones se leen de logs/escape.conf, NUNCA de Configuration:
 * Configuration::loadConfiguration() llama a bqSQL(), o sea a pSQL(), o sea a
 * este mismo metodo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class Db extends DbCore
{
    /** @var array|null opciones leidas de logs/escape.conf */
    private static $ecomAmConf = null;

    /** @var int incidencias apuntadas en esta peticion */
    private static $ecomAmApuntadas = 0;

    /** @var array firmas ya apuntadas en esta peticion */
    private static $ecomAmVistas = array();

    /** @var int tope de lineas por peticion */
    const ECOM_AM_TOPE = 20;

    /**
     * @param mixed $string
     * @param bool $html_ok
     * @param bool $bq_sql
     *
     * @return string
     */
    public function escape($string, $html_ok = false, $bq_sql = false)
    {
        // Camino normal: una llamada de cada mil llega aqui con otra cosa.
        if (is_scalar($string)) {
            return parent::escape($string, $html_ok, $bq_sql);
        }

        if ($string === null) {
            return parent::escape('', $html_ok, $bq_sql);
        }

        if (is_array($string)) {
            $conf = self::ecomAmConf();
            self::ecomAmApuntar($string, $conf);
            $string = self::ecomAmAplanar($string, $conf);

            return parent::escape($string, $html_ok, $bq_sql);
        }

        if (is_object($string)) {
            $conf = self::ecomAmConf();
            self::ecomAmApuntar($string, $conf);
            $string = method_exists($string, '__toString') ? (string) $string : '';

            return parent::escape($string, $html_ok, $bq_sql);
        }

        // Recursos y cualquier otra cosa rara.
        return parent::escape('', $html_ok, $bq_sql);
    }

    /**
     * @return string ruta de la carpeta logs/ del modulo, con la barra final
     */
    private static function ecomAmCarpeta()
    {
        if (defined('_PS_MODULE_DIR_')) {
            return _PS_MODULE_DIR_ . 'ecom_aftermigration/logs/';
        }

        // override/classes/db/ -> raiz de la tienda
        return dirname(dirname(dirname(__DIR__))) . '/modules/ecom_aftermigration/logs/';
    }

    /**
     * @return array
     */
    private static function ecomAmConf()
    {
        if (self::$ecomAmConf !== null) {
            return self::$ecomAmConf;
        }

        $conf = array('modo' => 'idioma', 'log' => true, 'id_lang' => 1);
        $fichero = self::ecomAmCarpeta() . 'escape.conf';

        if (@is_readable($fichero)) {
            $lineas = @file($fichero, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($lineas) ? $lineas : array() as $linea) {
                $trozos = explode('=', $linea, 2);
                if (count($trozos) !== 2) {
                    continue;
                }
                $clave = trim($trozos[0]);
                $valor = trim($trozos[1]);

                if ($clave === 'modo' && in_array($valor, array('idioma', 'unir', 'vaciar'), true)) {
                    $conf['modo'] = $valor;
                } elseif ($clave === 'log') {
                    $conf['log'] = ($valor === '1');
                } elseif ($clave === 'id_lang') {
                    $conf['id_lang'] = (int) $valor;
                }
            }
        }

        self::$ecomAmConf = $conf;

        return $conf;
    }

    /**
     * Convierte el array en la cadena que se va a guardar.
     *
     * @param array $valores
     * @param array $conf
     *
     * @return string
     */
    private static function ecomAmAplanar($valores, array $conf)
    {
        if ($conf['modo'] === 'vaciar') {
            return '';
        }

        $planos = array();
        foreach ($valores as $clave => $valor) {
            if (is_array($valor)) {
                $valor = self::ecomAmAplanar($valor, $conf);
            } elseif (is_object($valor)) {
                $valor = method_exists($valor, '__toString') ? (string) $valor : '';
            } elseif ($valor === null || is_bool($valor)) {
                $valor = (string) (int) $valor;
            }

            $planos[$clave] = (string) $valor;
        }

        if ($conf['modo'] === 'idioma') {
            // El caso tipico es un campo multiidioma: array(1 => 'uno', 2 => 'dos').
            $idLang = (int) $conf['id_lang'];
            if (isset($planos[$idLang]) && $planos[$idLang] !== '') {
                return $planos[$idLang];
            }

            foreach ($planos as $valor) {
                if ($valor !== '') {
                    return $valor;
                }
            }

            return '';
        }

        return implode(',', $planos);
    }

    /**
     * Apunta de donde ha salido el array, con tope por peticion y sin repetir.
     *
     * @param mixed $valor
     * @param array $conf
     */
    private static function ecomAmApuntar($valor, array $conf)
    {
        if (empty($conf['log']) || self::$ecomAmApuntadas >= self::ECOM_AM_TOPE) {
            return;
        }

        $origen = self::ecomAmOrigen();
        $firma = md5($origen);

        if (isset(self::$ecomAmVistas[$firma])) {
            return;
        }
        self::$ecomAmVistas[$firma] = true;
        ++self::$ecomAmApuntadas;

        if (is_array($valor)) {
            $claves = array();
            foreach (array_keys($valor) as $clave) {
                $claves[] = is_int($clave) ? $clave : (string) $clave;
                if (count($claves) >= 10) {
                    break;
                }
            }
            $descripcion = 'array(' . count($valor) . ') claves: ' . implode(', ', $claves);
        } elseif (is_object($valor)) {
            $descripcion = 'objeto ' . get_class($valor);
        } else {
            $descripcion = gettype($valor);
        }

        $linea = date('Y-m-d H:i:s') . ' | ' . $descripcion . ' | ' . $origen . PHP_EOL;

        $carpeta = self::ecomAmCarpeta();
        if (!@is_dir($carpeta)) {
            @mkdir($carpeta, 0755, true);
        }
        if (@is_dir($carpeta)) {
            @file_put_contents($carpeta . 'escape.log', $linea, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Primer punto de la pila que no es ni este fichero ni pSQL().
     *
     * @return string
     */
    private static function ecomAmOrigen()
    {
        $pila = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        $trozos = array();

        foreach ($pila as $marco) {
            $fichero = isset($marco['file']) ? $marco['file'] : '';
            $base = basename($fichero);

            if ($base === 'Db.php' || $base === 'alias.php' || $fichero === '') {
                continue;
            }

            $trozos[] = $fichero . ':' . (isset($marco['line']) ? $marco['line'] : 0)
                . ' ' . (isset($marco['class']) ? $marco['class'] . '::' : '')
                . (isset($marco['function']) ? $marco['function'] . '()' : '');

            if (count($trozos) >= 3) {
                break;
            }
        }

        return count($trozos) ? implode(' <- ', $trozos) : 'origen desconocido';
    }
}
