<?php
/**
 * Ecom Aftermigration - Filas de ps_configuration_lang que faltan
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Configuration::loadConfiguration() decide si una clave es multiidioma asi:
 *
 *     $lang = ($fila['id_lang']) ? $fila['id_lang'] : 0;
 *     self::$types[$fila['name']] = (bool) $lang;
 *
 * es decir, por el LEFT JOIN con ps_configuration_lang. Si una clave se queda sin
 * filas en esa tabla, isLangKey() pasa a devolver false y
 * PrestaShop\PrestaShop\Adapter\Configuration::get() devuelve una CADENA donde el
 * formulario espera un array por idioma. El resultado es la pantalla
 * Pedidos > Facturas caida con:
 *
 *     Expected argument of type "object, array or empty", "string" given
 *     PropertyPathMapper::mapDataToForms()
 *
 * Pasa igual en Albaranes, Facturas por abono, Devoluciones de mercancia,
 * Preferencias de producto y Mantenimiento, porque todos usan TranslatableType.
 */
class EcomAmCheckConfigLang extends EcomAmCheck
{
    /**
     * Claves multiidioma del nucleo. Las tres primeras vienen de
     * install/langs/<iso>/data/configuration.xml y el resto de los formularios
     * del back-office que las declaran TranslatableType.
     *
     * @var array
     */
    private $clavesNucleo = array(
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

    public function getCodigo()
    {
        return 'configuration_lang';
    }

    public function getTitulo()
    {
        return $this->t('Multilanguage configuration keys');
    }

    public function getDescripcion()
    {
        return $this->t('A key without rows in configuration_lang brings down Orders > Invoices and the other forms that use translatable fields.');
    }

    /**
     * Claves que la tienda ya trata como multiidioma, mas las del nucleo.
     *
     * @return array
     */
    private function clavesMultiidioma()
    {
        $claves = $this->clavesNucleo;

        $filas = Db::getInstance()->executeS(
            'SELECT DISTINCT c.`name`
             FROM `' . _DB_PREFIX_ . 'configuration` c
             INNER JOIN `' . _DB_PREFIX_ . 'configuration_lang` cl ON cl.`id_configuration` = c.`id_configuration`'
        );

        foreach (is_array($filas) ? $filas : array() as $fila) {
            $claves[] = (string) $fila['name'];
        }

        return array_values(array_unique($claves));
    }

    /**
     * @return array
     */
    private function analizar()
    {
        $idiomas = $this->idiomas();
        $claves = $this->clavesMultiidioma();

        $entrecomilladas = array();
        foreach ($claves as $clave) {
            $entrecomilladas[] = "'" . pSQL($clave) . "'";
        }

        $faltan = array();
        $filas = array();

        if (count($entrecomilladas) && count($idiomas)) {
            $filas = Db::getInstance()->executeS(
                'SELECT c.`id_configuration`, c.`name`, c.`id_shop`, c.`value`
                 FROM `' . _DB_PREFIX_ . 'configuration` c
                 WHERE c.`name` IN (' . implode(',', $entrecomilladas) . ')'
            );
        }

        $ids = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $ids[] = (int) $fila['id_configuration'];
        }

        $existentes = array();
        if (count($ids)) {
            $porIdioma = Db::getInstance()->executeS(
                'SELECT `id_configuration`, `id_lang`, `value`
                 FROM `' . _DB_PREFIX_ . 'configuration_lang`
                 WHERE `id_configuration` IN (' . implode(',', $ids) . ')'
            );

            foreach (is_array($porIdioma) ? $porIdioma : array() as $fila) {
                $existentes[(int) $fila['id_configuration']][(int) $fila['id_lang']] = $fila['value'];
            }
        }

        foreach (is_array($filas) ? $filas : array() as $fila) {
            $id = (int) $fila['id_configuration'];
            $tiene = isset($existentes[$id]) ? $existentes[$id] : array();

            foreach ($idiomas as $idLang) {
                if (array_key_exists($idLang, $tiene)) {
                    continue;
                }

                $faltan[] = array(
                    'id_configuration' => $id,
                    'name' => (string) $fila['name'],
                    'id_shop' => (int) $fila['id_shop'],
                    'id_lang' => (int) $idLang,
                    'valor' => $this->valorDeReferencia($tiene, $fila['value']),
                );
            }
        }

        return array(
            'faltan' => $faltan,
            'huerfanas' => $this->contarHuerfanas(),
            'idiomas_muertos' => $this->contarIdiomasMuertos(),
        );
    }

    /**
     * Valor con el que se rellena una fila que falta: primero el del idioma por
     * defecto, luego el de cualquier otro idioma y por ultimo el de ps_configuration.
     *
     * @param array $tiene
     * @param mixed $valorGlobal
     *
     * @return string
     */
    private function valorDeReferencia(array $tiene, $valorGlobal)
    {
        $porDefecto = $this->idiomaPorDefecto();
        if (isset($tiene[$porDefecto]) && $tiene[$porDefecto] !== null && $tiene[$porDefecto] !== '') {
            return (string) $tiene[$porDefecto];
        }

        foreach ($tiene as $valor) {
            if ($valor !== null && $valor !== '') {
                return (string) $valor;
            }
        }

        return $valorGlobal === null ? '' : (string) $valorGlobal;
    }

    /**
     * @return int filas de configuration_lang sin su fila en configuration
     */
    private function contarHuerfanas()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'configuration_lang` cl
             LEFT JOIN `' . _DB_PREFIX_ . 'configuration` c ON c.`id_configuration` = cl.`id_configuration`
             WHERE c.`id_configuration` IS NULL'
        );
    }

    /**
     * @return int filas de configuration_lang de un idioma que ya no existe
     */
    private function contarIdiomasMuertos()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'configuration_lang` cl
             LEFT JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = cl.`id_lang`
             WHERE l.`id_lang` IS NULL'
        );
    }

    public function comprobar()
    {
        $analisis = $this->analizar();
        $faltan = count($analisis['faltan']);

        if (!$faltan && !$analisis['huerfanas'] && !$analisis['idiomas_muertos']) {
            return $this->resultado(
                self::ESTADO_OK,
                $this->t('Every multilanguage key has one row per language.')
            );
        }

        $detalle = array();
        $porClave = array();
        foreach ($analisis['faltan'] as $fila) {
            $etiqueta = $fila['name'] . ' (id_shop ' . $fila['id_shop'] . ')';
            if (!isset($porClave[$etiqueta])) {
                $porClave[$etiqueta] = array();
            }
            $porClave[$etiqueta][] = $fila['id_lang'];
        }

        foreach ($porClave as $etiqueta => $langs) {
            $detalle[] = $etiqueta . ' -> ' . $this->t('missing languages:') . ' ' . implode(', ', $langs);
        }

        if ($analisis['huerfanas']) {
            $detalle[] = $this->t('%count% orphan row(s) in configuration_lang.', array('%count%' => $analisis['huerfanas']));
        }
        if ($analisis['idiomas_muertos']) {
            $detalle[] = $this->t('%count% row(s) belong to a language that no longer exists.', array('%count%' => $analisis['idiomas_muertos']));
        }

        return $this->resultado(
            $faltan ? self::ESTADO_FALLO : self::ESTADO_AVISO,
            $faltan
                ? $this->t('%count% row(s) are missing in configuration_lang.', array('%count%' => $faltan))
                : $this->t('There are leftover rows in configuration_lang.'),
            $detalle
        );
    }

    public function arreglar()
    {
        $db = Db::getInstance();
        $analisis = $this->analizar();
        $insertadas = 0;

        foreach ($analisis['faltan'] as $fila) {
            $ok = $db->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'configuration_lang`
                 (`id_configuration`, `id_lang`, `value`, `date_upd`)
                 VALUES (' . (int) $fila['id_configuration'] . ', ' . (int) $fila['id_lang'] . ", '"
                 . pSQL($fila['valor'], true) . "', NOW())"
            );

            if ($ok) {
                ++$insertadas;
            }
        }

        if ($analisis['huerfanas']) {
            $db->execute(
                'DELETE cl FROM `' . _DB_PREFIX_ . 'configuration_lang` cl
                 LEFT JOIN `' . _DB_PREFIX_ . 'configuration` c ON c.`id_configuration` = cl.`id_configuration`
                 WHERE c.`id_configuration` IS NULL'
            );
        }

        if ($analisis['idiomas_muertos']) {
            $db->execute(
                'DELETE cl FROM `' . _DB_PREFIX_ . 'configuration_lang` cl
                 LEFT JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = cl.`id_lang`
                 WHERE l.`id_lang` IS NULL'
            );
        }

        // La cache de Configuration ya esta cargada en memoria con los datos viejos.
        if (method_exists('Configuration', 'resetStaticCache')) {
            Configuration::resetStaticCache();
        } elseif (method_exists('Configuration', 'clearConfigurationCacheForTesting')) {
            Configuration::clearConfigurationCacheForTesting();
        }
        Configuration::loadConfiguration();

        $despues = $this->analizar();
        $quedan = count($despues['faltan']);

        return $this->arreglado(
            $quedan === 0,
            $quedan === 0
                ? $this->t('%count% row(s) inserted in configuration_lang.', array('%count%' => $insertadas))
                : $this->t('%count% row(s) are still missing after the repair.', array('%count%' => $quedan))
        );
    }
}
