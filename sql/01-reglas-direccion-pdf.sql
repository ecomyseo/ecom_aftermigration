-- ---------------------------------------------------------------------------
-- Ecom Aftermigration - Repone PS_INVCE_INVOICE_ADDR_RULES y PS_INVCE_DELIVERY_ADDR_RULES
--
-- Arregla este fatal al validar un pedido:
--
--     Fatal error: Uncaught TypeError: array_key_exists(): Argument #2 ($array)
--     must be of type array, null given in classes/AddressFormat.php:446
--     #0 classes/pdf/HTMLTemplateInvoice.php(167): AddressFormatCore::generateAddress()
--     #1 classes/pdf/PDF.php(146): HTMLTemplateInvoiceCore->getContent()
--     #2 classes/PaymentModule.php(621): PDFCore->render()
--
-- Causa: HTMLTemplateInvoice hace json_decode(Configuration::get('PS_INVCE_INVOICE_ADDR_RULES'), true).
-- Si la clave no existe, Configuration::get() devuelve false, json_decode(false, true)
-- devuelve null, y AddressFormat::generateAddress() llama a array_key_exists() con null.
-- En PHP 7 era un aviso; en PHP 8 es un fatal y el pedido no se puede terminar.
--
-- El valor de fabrica es {"avoid":[]} (install/data/xml/configuration.xml).
--
-- HAZ UNA COPIA DE LA BASE DE DATOS ANTES. Cambia el prefijo ps_ por el de la tienda.
--
-- @author    Ecom Experts <ecomyseo@gmail.com>
-- @copyright 2026 Ecom Experts
-- @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
-- ---------------------------------------------------------------------------

-- 1. Repone las filas que existen pero no tienen un JSON valido.
UPDATE `ps_configuration`
SET `value` = '{"avoid":[]}',
    `date_upd` = NOW()
WHERE `name` IN ('PS_INVCE_INVOICE_ADDR_RULES', 'PS_INVCE_DELIVERY_ADDR_RULES')
  AND (`value` IS NULL OR TRIM(`value`) = '' OR `value` NOT LIKE '{%');

-- 2. Crea la fila global si no hay ninguna (ni global ni por tienda).
INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`)
SELECT NULL, NULL, 'PS_INVCE_INVOICE_ADDR_RULES', '{"avoid":[]}', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `name` FROM `ps_configuration`) z
    WHERE z.`name` = 'PS_INVCE_INVOICE_ADDR_RULES'
);

INSERT INTO `ps_configuration` (`id_shop_group`, `id_shop`, `name`, `value`, `date_add`, `date_upd`)
SELECT NULL, NULL, 'PS_INVCE_DELIVERY_ADDR_RULES', '{"avoid":[]}', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `name` FROM `ps_configuration`) z
    WHERE z.`name` = 'PS_INVCE_DELIVERY_ADDR_RULES'
);

-- 3. Comprobacion: tienen que salir las dos con {"avoid":[]}.
SELECT `id_configuration`, `id_shop`, `name`, `value`
FROM `ps_configuration`
WHERE `name` IN ('PS_INVCE_INVOICE_ADDR_RULES', 'PS_INVCE_DELIVERY_ADDR_RULES');
