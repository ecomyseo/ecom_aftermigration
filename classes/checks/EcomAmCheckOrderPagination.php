<?php
/**
 * Ecom Aftermigration - Paginacion de productos del pedido convertida por error en multiidioma
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PS_ORDER_PRODUCTS_NB_PER_PAGE es una configuracion ESCALAR.
 *
 * Si una migracion deja filas para esta clave en ps_configuration_lang,
 * Configuration::get() puede devolver un array en lugar de un valor numerico.
 * En PrestaShop 9.x OrderController lo envia a Twig como paginationNum y
 * products.html.twig termina ejecutando:
 *
 *     orderForViewing.products.products|slice(0, paginationNum)
 *
 * Twig acaba llamando a array_slice() con un array como tercer argumento y
 * la vista del pedido cae con TypeError.
 */
class EcomAmCheckOrderPagination extends EcomAmCheck
{
    const CLAVE = 'PS_ORDER_PRODUCTS_NB_PER_PAGE';
    const VALOR_DEFECTO = 8;

    /** @var array */
    private $valoresValidos = array(8, 20, 50, 100);

    public function getCodigo()
    {
        return 'order_products_pagination';
    }

    public function getTitulo()
    {
        return $this->t('Order products pagination');
    }

    public function getDescripcion()
    {
        return $this->t('Detects when PS_ORDER_PRODUCTS_NB_PER_PAGE has been converted into a multilanguage value and breaks the order view in PrestaShop 9.');
    }

    /**
     * @return array
     */
    private function analizar()
    {
        $db = Db::getInstance();
        $filas = $db->executeS(
            'SELECT `id_configuration`, `id_shop_group`, `id_shop`, `value`
             FROM `' . _DB_PREFIX_ . 'configuration`
             WHERE `name` = \'' . pSQL(self::CLAVE) . '\''
        );

        $ids = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $ids[] = (int) $fila['id_configuration'];
        }

        $filasLang = array();
        if (count($ids)) {
            $filasLang = $db->executeS(
                'SELECT `id_configuration`, `id_lang`, `value`
                 FROM `' . _DB_PREFIX_ . 'configuration_lang`
                 WHERE `id_configuration` IN (' . implode(',', $ids) . ')'
            );
        }

        $valoresInvalidos = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $valor = $fila['value'];
            $entero = is_numeric($valor) ? (int) $valor : 0;

            if (!in_array($entero, $this->valoresValidos, true)) {
                $valoresInvalidos[] = array(
                    'id_configuration' => (int) $fila['id_configuration'],
                    'id_shop' => (int) $fila['id_shop'],
                    'value' => $valor,
                );
            }
        }

        return array(
            'configuraciones' => is_array($filas) ? $filas : array(),
            'filas_lang' => is_array($filasLang) ? $filasLang : array(),
            'valores_invalidos' => $valoresInvalidos,
        );
    }

    public function comprobar()
    {
        $analisis = $this->analizar();
        $numLang = count($analisis['filas_lang']);
        $numInvalidos = count($analisis['valores_invalidos']);

        if (!$numLang && !$numInvalidos) {
            return $this->resultado(
                self::ESTADO_OK,
                $this->t('PS_ORDER_PRODUCTS_NB_PER_PAGE is a valid scalar value.')
            );
        }

        $detalle = array();

        if ($numLang) {
            $detalle[] = $this->t(
                '%count% unexpected row(s) found in configuration_lang for PS_ORDER_PRODUCTS_NB_PER_PAGE.',
                array('%count%' => $numLang)
            );
        }

        foreach ($analisis['valores_invalidos'] as $fila) {
            $detalle[] = self::CLAVE
                . ' (id_configuration ' . (int) $fila['id_configuration']
                . ', id_shop ' . (int) $fila['id_shop'] . ') = '
                . var_export($fila['value'], true);
        }

        return $this->resultado(
            self::ESTADO_FALLO,
            $this->t('The order products pagination value can reach Twig as an array and break the order view.'),
            $detalle
        );
    }

    public function arreglar()
    {
        $db = Db::getInstance();
        $analisis = $this->analizar();
        $ids = array();

        foreach ($analisis['configuraciones'] as $fila) {
            $ids[] = (int) $fila['id_configuration'];
        }

        $borradas = 0;
        if (count($ids)) {
            $borradas = (int) count($analisis['filas_lang']);
            $db->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'configuration_lang`
                 WHERE `id_configuration` IN (' . implode(',', $ids) . ')'
            );
        }

        $corregidas = 0;
        foreach ($analisis['valores_invalidos'] as $fila) {
            $ok = $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'configuration`
                 SET `value` = \'' . (int) self::VALOR_DEFECTO . '\', `date_upd` = NOW()
                 WHERE `id_configuration` = ' . (int) $fila['id_configuration']
            );
            if ($ok) {
                ++$corregidas;
            }
        }

        // Si la clave no existe, la recreamos como escalar con un valor seguro.
        if (!count($analisis['configuraciones'])) {
            Configuration::updateValue(self::CLAVE, self::VALOR_DEFECTO);
            ++$corregidas;
        }

        // Fuerza a que Configuration vuelva a calcular el tipo de la clave.
        if (method_exists('Configuration', 'resetStaticCache')) {
            Configuration::resetStaticCache();
        } elseif (method_exists('Configuration', 'clearConfigurationCacheForTesting')) {
            Configuration::clearConfigurationCacheForTesting();
        }
        Configuration::loadConfiguration();

        $despues = $this->analizar();
        $ok = count($despues['filas_lang']) === 0 && count($despues['valores_invalidos']) === 0;

        return $this->arreglado(
            $ok,
            $ok
                ? $this->t(
                    'Order pagination repaired: %rows% language row(s) removed and %values% scalar value(s) restored.',
                    array('%rows%' => $borradas, '%values%' => $corregidas)
                )
                : $this->t('The order pagination configuration is still invalid after the repair.')
        );
    }
}
