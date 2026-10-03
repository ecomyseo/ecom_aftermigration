<?php
/**
 * Ecom Aftermigration - Reglas de direccion de los PDF (PS_INVCE_*_ADDR_RULES)
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * classes/pdf/HTMLTemplateInvoice.php hace, sin comprobar nada:
 *
 *     $reglas = json_decode(Configuration::get('PS_INVCE_INVOICE_ADDR_RULES'), true);
 *     AddressFormat::generateAddress($direccion, $reglas, '<br />', ' ');
 *
 * Si la clave no existe, Configuration::get() devuelve false, json_decode(false, true)
 * devuelve null, y AddressFormat::generateAddress() hace array_key_exists('avoid', null).
 * En PHP 8 eso es un fatal.
 *
 * Estas dos claves son escalares, NO multiidioma. Algunas migraciones dejan filas
 * antiguas en configuration_lang. En ese estado Configuration::updateValue() puede
 * devolver true sin persistir el valor escalar en configuration: la reparacion parece
 * funcionar durante la peticion actual (cache) y vuelve a romperse al recargar.
 *
 * Por eso este chequeo normaliza directamente las tablas de configuracion:
 * - elimina configuration_lang para estas claves;
 * - restaura {"avoid":[]} en todas las filas existentes;
 * - garantiza una fila global;
 * - reinicia la cache estatica de Configuration antes de verificar.
 */
class EcomAmCheckPdfAddrRules extends EcomAmCheck
{
    /** @var array */
    private $claves = array(
        'PS_INVCE_INVOICE_ADDR_RULES',
        'PS_INVCE_DELIVERY_ADDR_RULES',
    );

    /** @var string valor de fabrica */
    const VALOR_POR_DEFECTO = '{"avoid":[]}';

    public function getCodigo()
    {
        return 'pdf_addr_rules';
    }

    public function getTitulo()
    {
        return $this->t('Address rules of the invoice PDF');
    }

    public function getDescripcion()
    {
        return $this->t('Without them, validating an order ends in a fatal error and the customer cannot finish the purchase.');
    }

    /**
     * @return array id de todas las tiendas, activas o no
     */
    private function tiendas()
    {
        $ids = Shop::getShops(false, null, true);
        if (!is_array($ids) || !count($ids)) {
            return array((int) Configuration::get('PS_SHOP_DEFAULT'));
        }

        $salida = array();
        foreach ($ids as $id) {
            $salida[] = (int) $id;
        }

        return $salida;
    }

    /**
     * @return array pares tienda/clave que estan mal
     */
    private function rotas()
    {
        $rotas = array();

        foreach ($this->tiendas() as $idShop) {
            foreach ($this->claves as $clave) {
                $valor = Configuration::get($clave, null, null, $idShop);
                $decodificado = is_string($valor) ? json_decode($valor, true) : null;

                if (!is_array($decodificado)) {
                    $rotas[] = array(
                        'id_shop' => $idShop,
                        'clave' => $clave,
                        'valor' => is_string($valor) ? $valor : gettype($valor),
                    );
                }
            }
        }

        return $rotas;
    }

    /**
     * Estas claves son escalares. Las deja en un estado canonico y persistente.
     *
     * @param string $clave
     *
     * @return bool
     */
    private function normalizarClave($clave)
    {
        $db = Db::getInstance();
        $claveSql = pSQL($clave);
        $valorSql = pSQL(self::VALOR_POR_DEFECTO);

        // 1) Quitar cualquier marca multiidioma accidental para esta clave.
        $ok = $db->execute(
            'DELETE cl
             FROM `' . _DB_PREFIX_ . 'configuration_lang` cl
             INNER JOIN `' . _DB_PREFIX_ . 'configuration` c
                ON c.`id_configuration` = cl.`id_configuration`
             WHERE c.`name` = \'' . $claveSql . '\''
        );

        if (!$ok) {
            return false;
        }

        // 2) Todas las filas existentes (global/grupo/tienda) deben contener JSON valido.
        $ok = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'configuration`
             SET `value` = \'' . $valorSql . '\', `date_upd` = NOW()
             WHERE `name` = \'' . $claveSql . '\''
        );

        if (!$ok) {
            return false;
        }

        // 3) Debe existir SIEMPRE una fila global, aunque haya filas por tienda.
        $existeGlobal = (int) $db->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'configuration`
             WHERE `name` = \'' . $claveSql . '\'
               AND (`id_shop_group` IS NULL OR `id_shop_group` = 0)
               AND (`id_shop` IS NULL OR `id_shop` = 0)'
        );

        if (!$existeGlobal) {
            $ahora = date('Y-m-d H:i:s');
            $ok = $db->insert(
                'configuration',
                array(
                    'id_shop_group' => null,
                    'id_shop' => null,
                    'name' => $clave,
                    'value' => self::VALOR_POR_DEFECTO,
                    'date_add' => $ahora,
                    'date_upd' => $ahora,
                ),
                false,
                true,
                Db::INSERT
            );

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fuerza que Configuration vuelva a leer la BD despues de la normalizacion.
     */
    private function reiniciarCacheConfiguracion()
    {
        if (method_exists('Configuration', 'resetStaticCache')) {
            Configuration::resetStaticCache();

            return;
        }

        // Compatibilidad con ramas antiguas que aun expongan este alias.
        if (method_exists('Configuration', 'clearConfigurationCacheForTesting')) {
            Configuration::clearConfigurationCacheForTesting();
        }
    }

    public function comprobar()
    {
        $rotas = $this->rotas();

        if (!count($rotas)) {
            return $this->resultado(
                self::ESTADO_OK,
                $this->t('Both keys hold valid JSON in every shop.')
            );
        }

        $detalle = array();
        foreach ($rotas as $rota) {
            $detalle[] = $rota['clave'] . ' (id_shop ' . $rota['id_shop'] . '): ' . $rota['valor'];
        }

        return $this->resultado(
            self::ESTADO_FALLO,
            $this->t(
                '%count% configuration key(s) are missing or do not hold valid JSON. Validating an order will end in a fatal error.',
                array('%count%' => count($rotas))
            ),
            $detalle
        );
    }

    public function arreglar()
    {
        $rotas = $this->rotas();
        $clavesRotas = array();

        foreach ($rotas as $rota) {
            $clavesRotas[$rota['clave']] = true;
        }

        $arregladas = 0;
        $detalle = array();

        foreach (array_keys($clavesRotas) as $clave) {
            if ($this->normalizarClave($clave)) {
                ++$arregladas;
                $detalle[] = $clave . ' = ' . self::VALOR_POR_DEFECTO . ' (scalar/global normalized)';
            } else {
                $detalle[] = $clave . ': database normalization failed';
            }
        }

        // IMPORTANTE: sin esto Configuration::get() puede seguir devolviendo el valor
        // viejo de la cache durante esta misma peticion.
        $this->reiniciarCacheConfiguracion();

        // Releer desde BD para no dar por bueno algo que no se haya persistido.
        $quedan = count($this->rotas());

        return $this->arreglado(
            $quedan === 0,
            $quedan === 0
                ? $this->t('%count% key(s) restored to the factory value.', array('%count%' => $arregladas))
                : $this->t('%count% key(s) are still wrong after the repair.', array('%count%' => $quedan)),
            $detalle
        );
    }
}
