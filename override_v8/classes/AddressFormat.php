<?php
/**
 * ECOM_AFTERMIGRATION_OVERRIDE
 *
 * Ecom Aftermigration - AddressFormat::generateAddress() deja de ser un fatal
 *
 * El nucleo hace, en classes/AddressFormat.php (linea 446 en 8.1 y 8.2, 424 en 9.1):
 *
 *     if (!array_key_exists('avoid', $patternRules) || !in_array($pattern, $patternRules['avoid'])) {
 *
 * sin comprobar que $patternRules sea un array. Y classes/pdf/HTMLTemplateInvoice.php
 * se lo pasa asi:
 *
 *     $invoiceAddressPatternRules = json_decode(Configuration::get('PS_INVCE_INVOICE_ADDR_RULES'), true);
 *
 * Si esa clave de configuracion no existe (migraciones que se la dejan), Configuration::get()
 * devuelve false, json_decode(false, true) devuelve null, y en PHP 8 sale:
 *
 *     array_key_exists(): Argument #2 ($array) must be of type array, null given
 *     in classes/AddressFormat.php on line 446
 *
 * Como la factura se genera dentro de PaymentModule::validateOrder(), el cliente se
 * queda sin poder terminar la compra: el pedido muere al validarlo.
 *
 * Poner 'avoid' => array() no cambia el comportamiento: !in_array($pattern, array())
 * es siempre true, exactamente igual que cuando no hay reglas.
 *
 * El arreglo de verdad es reponer las dos claves de configuracion (lo hace el modulo
 * ecom_aftermigration, chequeo "pdf_addr_rules"). Este override es la red de abajo,
 * porque cualquier modulo que llame a generateAddress() con null hace lo mismo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AddressFormat extends AddressFormatCore
{
    /**
     * @param Address $address
     * @param array $patternRules
     * @param string $newLine
     * @param string $separator
     * @param array $style
     *
     * @return string
     */
    public static function generateAddress(
        Address $address,
        $patternRules = array(),
        $newLine = self::FORMAT_NEW_LINE,
        $separator = ' ',
        $style = array()
    ) {
        if (!is_array($patternRules)) {
            $patternRules = array();
        }

        if (!isset($patternRules['avoid']) || !is_array($patternRules['avoid'])) {
            $patternRules['avoid'] = array();
        }

        if (!is_array($style)) {
            $style = array();
        }

        if (!is_string($newLine)) {
            $newLine = self::FORMAT_NEW_LINE;
        }

        if (!is_string($separator)) {
            $separator = ' ';
        }

        return parent::generateAddress($address, $patternRules, $newLine, $separator, $style);
    }
}
