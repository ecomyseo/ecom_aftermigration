<?php
/**
 * Ecom Aftermigration - Instalacion manual de los overrides
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Copia manual de override_v8/ a override/. Nunca desde install():
 * installOverrides() del nucleo fusiona ficheros y rompe los de otros modulos.
 */
class EcomAmOverrides
{
    /** @var string marca que permite reconocer nuestros ficheros */
    const MARCA = 'ECOM_AFTERMIGRATION_OVERRIDE';

    const ESTADO_AUSENTE = 'ausente';
    const ESTADO_INSTALADO = 'instalado';
    const ESTADO_ANTIGUO = 'antiguo';
    const ESTADO_AJENO = 'ajeno';

    /**
     * Rutas relativas a override/ y a override_v8/.
     *
     * @return array
     */
    public static function ficheros()
    {
        return array(
            'classes/db/Db.php',
            'classes/AddressFormat.php',
        );
    }

    /**
     * @return string
     */
    public static function carpetaOrigen()
    {
        return _PS_MODULE_DIR_ . 'ecom_aftermigration/override_v8/';
    }

    /**
     * @return string
     */
    public static function carpetaDestino()
    {
        if (defined('_PS_OVERRIDE_DIR_')) {
            return _PS_OVERRIDE_DIR_;
        }

        return _PS_ROOT_DIR_ . '/override/';
    }

    /**
     * @return array estado de cada fichero mas el de la opcion global de overrides
     */
    public static function estado()
    {
        $origen = self::carpetaOrigen();
        $destino = self::carpetaDestino();

        $ficheros = array();
        foreach (self::ficheros() as $relativo) {
            $rutaOrigen = $origen . $relativo;
            $rutaDestino = $destino . $relativo;

            $estado = self::ESTADO_AUSENTE;
            if (file_exists($rutaDestino)) {
                $contenido = (string) @file_get_contents($rutaDestino);
                if (strpos($contenido, self::MARCA) === false) {
                    $estado = self::ESTADO_AJENO;
                } elseif (file_exists($rutaOrigen) && md5($contenido) === md5((string) @file_get_contents($rutaOrigen))) {
                    $estado = self::ESTADO_INSTALADO;
                } else {
                    $estado = self::ESTADO_ANTIGUO;
                }
            }

            $ficheros[] = array(
                'relativo' => $relativo,
                'destino' => $rutaDestino,
                'estado' => $estado,
            );
        }

        return array(
            'ficheros' => $ficheros,
            'desactivados' => self::overridesDesactivados(),
            'destino' => $destino,
            'escribible' => is_writable($destino) || is_writable(dirname(rtrim($destino, '/\\'))),
        );
    }

    /**
     * @return bool true si PrestaShop esta ignorando todos los overrides
     */
    public static function overridesDesactivados()
    {
        if (defined('_PS_DISABLE_OVERRIDES_') && _PS_DISABLE_OVERRIDES_) {
            return true;
        }

        return (bool) Configuration::get('PS_DISABLE_OVERRIDES');
    }

    /**
     * @return array array('ok' => bool, 'mensaje' => string)
     */
    public static function instalar()
    {
        $origen = self::carpetaOrigen();
        $destino = self::carpetaDestino();
        $copiados = array();
        $ajenos = array();

        foreach (self::ficheros() as $relativo) {
            $rutaOrigen = $origen . $relativo;
            $rutaDestino = $destino . $relativo;

            if (!file_exists($rutaOrigen)) {
                continue;
            }

            if (file_exists($rutaDestino)) {
                $contenido = (string) @file_get_contents($rutaDestino);
                if (strpos($contenido, self::MARCA) === false) {
                    // Hay un override de otro (o del propio comercio): no se pisa.
                    $ajenos[] = $relativo;
                    continue;
                }
                @copy($rutaDestino, $rutaDestino . '.bak-' . date('Ymd-His'));
            }

            $carpeta = dirname($rutaDestino);
            if (!is_dir($carpeta) && !@mkdir($carpeta, 0755, true)) {
                return array(
                    'ok' => false,
                    'mensaje' => self::t('The folder %folder% could not be created.', array('%folder%' => $carpeta)),
                );
            }

            self::ponerIndex($carpeta);

            if (!@copy($rutaOrigen, $rutaDestino)) {
                return array(
                    'ok' => false,
                    'mensaje' => self::t('%file% could not be copied. Check the write permissions of the override/ folder.', array('%file%' => $relativo)),
                );
            }

            $copiados[] = $relativo;
        }

        self::limpiarCache();
        EcomAmLog::escribir('Overrides copiados: ' . implode(', ', $copiados), true);

        if (count($ajenos)) {
            return array(
                'ok' => false,
                'mensaje' => self::t(
                    'Copied %count% file(s). %files% was left untouched because an override that does not belong to this module is already there: it has to be merged by hand.',
                    array('%count%' => count($copiados), '%files%' => implode(', ', $ajenos))
                ),
            );
        }

        return array(
            'ok' => true,
            'mensaje' => self::t('Overrides installed (%count% file(s)) and cache cleared.', array('%count%' => count($copiados))),
        );
    }

    /**
     * Solo borra los ficheros que llevan nuestra marca.
     *
     * @return array
     */
    public static function quitar()
    {
        $destino = self::carpetaDestino();
        $borrados = 0;

        foreach (self::ficheros() as $relativo) {
            $rutaDestino = $destino . $relativo;
            if (!file_exists($rutaDestino)) {
                continue;
            }

            $contenido = (string) @file_get_contents($rutaDestino);
            if (strpos($contenido, self::MARCA) === false) {
                continue;
            }

            if (@unlink($rutaDestino)) {
                ++$borrados;
            }
        }

        self::limpiarCache();
        EcomAmLog::escribir('Overrides retirados: ' . $borrados . ' fichero(s)', true);

        return array(
            'ok' => true,
            'mensaje' => self::t('Removed %count% override file(s) and cleared the cache.', array('%count%' => $borrados)),
        );
    }

    /**
     * Las cadenas van con self::t() y el extractor no las ve: estan declaradas
     * en Ecom_Aftermigration::cadenasParaElExtractor().
     *
     * @param string $texto
     * @param array $parametros
     *
     * @return string
     */
    private static function t($texto, array $parametros = array())
    {
        $modulo = Module::getInstanceByName('ecom_aftermigration');
        if (is_object($modulo) && method_exists($modulo, 'trans')) {
            return $modulo->trans($texto, $parametros, 'Modules.Ecomaftermigration.Admin');
        }

        return strtr($texto, $parametros);
    }

    /**
     * @param string $carpeta
     */
    private static function ponerIndex($carpeta)
    {
        $indice = rtrim($carpeta, '/\\') . '/index.php';
        if (!file_exists($indice)) {
            @file_put_contents($indice, "<?php\nheader('Location: ../');\nexit;\n");
        }
    }

    /**
     * El indice de clases es lo que decide si un override se carga o no.
     */
    public static function limpiarCache()
    {
        // El indice puede estar en var/cache/dev y en var/cache/prod a la vez: el
        // back-office y el front pueden correr en entornos distintos.
        foreach (self::indicesDeClases() as $indice) {
            if (file_exists($indice)) {
                @unlink($indice);
            }
        }

        // PS 8.2 y 9 usan PrestaShop\Autoload\PrestashopAutoload (con la "s" minuscula);
        // 1.7 y 8.0/8.1, la clase global PrestaShopAutoload.
        foreach (array('PrestaShop\\Autoload\\PrestashopAutoload', 'PrestaShopAutoload') as $clase) {
            try {
                if (class_exists($clase) && method_exists($clase, 'getInstance')) {
                    $autoload = call_user_func(array($clase, 'getInstance'));
                    if (is_object($autoload) && method_exists($autoload, 'generateIndex')) {
                        $autoload->generateIndex();
                        break;
                    }
                }
            } catch (Throwable $e) {
                EcomAmLog::escribir('No se ha podido regenerar el índice de clases: ' . $e->getMessage());
            }
        }

        // NUNCA Tools::clearAllCache() ni clearSf2Cache(): el propio nucleo avisa de que
        // "can result in unexpected behaviour with Container rebuild" (classes/Tools.php).
        // Vaciarla desde aqui deja la siguiente peticion en un 500. Y para que un override
        // legacy entre en juego basta con el indice de clases.
        try {
            if (method_exists('Tools', 'clearSmartyCache')) {
                Tools::clearSmartyCache();
            }
        } catch (Throwable $e) {
            EcomAmLog::escribir('No se ha podido vaciar la caché de Smarty: ' . $e->getMessage());
        }
    }

    /**
     * @return array rutas de todos los class_index.php de la tienda
     */
    private static function indicesDeClases()
    {
        $rutas = array();

        if (defined('_PS_CACHE_DIR_')) {
            $rutas[] = _PS_CACHE_DIR_ . 'class_index.php';
        }

        if (defined('_PS_ROOT_DIR_')) {
            foreach (array('/var/cache/*/class_index.php', '/cache/class_index.php') as $patron) {
                $encontradas = glob(_PS_ROOT_DIR_ . $patron);
                foreach (is_array($encontradas) ? $encontradas : array() as $ruta) {
                    $rutas[] = $ruta;
                }
            }
        }

        return array_values(array_unique($rutas));
    }
}
