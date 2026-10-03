-- ---------------------------------------------------------------------------
-- Ecom Aftermigration - Repone las filas que faltan en ps_configuration_lang
--
-- Arregla esta pantalla caida en Pedidos > Facturas (y en Albaranes, Facturas
-- por abono, Devoluciones de mercancia, Preferencias de producto y Mantenimiento):
--
--     Expected argument of type "object, array or empty", "string" given
--     Symfony\Component\Form\Exception\UnexpectedTypeException
--     PropertyPathMapper->mapDataToForms()
--     Handler->getForm() en src/Core/Form/Handler.php linea 114
--     InvoicesController->indexAction() linea 57
--
-- Causa: Configuration::loadConfiguration() decide si una clave es multiidioma
-- con el LEFT JOIN a ps_configuration_lang:
--
--     $lang = ($row['id_lang']) ? $row['id_lang'] : 0;
--     self::$types[$row['name']] = (bool) $lang;
--
-- Si una clave se queda sin filas ahi, isLangKey() devuelve false, el adaptador
-- PrestaShop\PrestaShop\Adapter\Configuration::get() devuelve una CADENA, y el
-- campo TranslatableType del formulario esperaba un array por idioma.
--
-- HAZ UNA COPIA DE LA BASE DE DATOS ANTES. Cambia el prefijo ps_ por el de la tienda.
--
-- @author    Ecom Experts <ecomyseo@gmail.com>
-- @copyright 2026 Ecom Experts
-- @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
-- ---------------------------------------------------------------------------

-- 1. Claves que YA tienen alguna fila de idioma: se copia la del idioma mas bajo
--    a todos los idiomas que falten. INSERT IGNORE no pisa lo que ya hay traducido.
INSERT IGNORE INTO `ps_configuration_lang` (`id_configuration`, `id_lang`, `value`, `date_upd`)
SELECT origen.`id_configuration`, l.`id_lang`, origen.`value`, NOW()
FROM (
    SELECT cl.`id_configuration`, cl.`value`
    FROM `ps_configuration_lang` cl
    INNER JOIN (
        SELECT `id_configuration`, MIN(`id_lang`) AS `id_lang`
        FROM `ps_configuration_lang`
        GROUP BY `id_configuration`
    ) m ON m.`id_configuration` = cl.`id_configuration` AND m.`id_lang` = cl.`id_lang`
) origen
CROSS JOIN `ps_lang` l;

-- 2. Claves multiidioma del nucleo que se han quedado SIN ninguna fila de idioma.
--    Se siembra con el valor de ps_configuration, que es donde quedo al migrar.
INSERT IGNORE INTO `ps_configuration_lang` (`id_configuration`, `id_lang`, `value`, `date_upd`)
SELECT c.`id_configuration`, l.`id_lang`, IFNULL(c.`value`, ''), NOW()
FROM `ps_configuration` c
CROSS JOIN `ps_lang` l
WHERE c.`name` IN (
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
        'PS_LABEL_OOS_PRODUCTS_BOD'
      )
  AND c.`id_configuration` NOT IN (
        SELECT `id_configuration` FROM (
            SELECT DISTINCT `id_configuration` FROM `ps_configuration_lang`
        ) z
      );

-- 3. Comprobacion: filas_lang tiene que ser igual a idiomas en todas.
SELECT c.`name`,
       c.`id_configuration`,
       c.`id_shop`,
       (SELECT COUNT(*) FROM `ps_configuration_lang` cl
        WHERE cl.`id_configuration` = c.`id_configuration`) AS `filas_lang`,
       (SELECT COUNT(*) FROM `ps_lang`) AS `idiomas`
FROM `ps_configuration` c
WHERE c.`id_configuration` IN (SELECT DISTINCT `id_configuration` FROM `ps_configuration_lang`)
ORDER BY `filas_lang` ASC, c.`name` ASC;

-- 4. DESPUES: vacia la cache de la tienda (var/cache/) o la pantalla seguira
--    igual, porque Configuration se cachea en el arranque.
