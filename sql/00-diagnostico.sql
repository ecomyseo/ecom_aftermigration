-- ---------------------------------------------------------------------------
-- Ecom Aftermigration - Diagnostico. SOLO LECTURA, no escribe nada.
--
-- Cambia el prefijo ps_ por el de la tienda antes de ejecutarlo.
--
-- @author    Ecom Experts <ecomyseo@gmail.com>
-- @copyright 2026 Ecom Experts
-- @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
-- ---------------------------------------------------------------------------

-- 1. Reglas de direccion de los PDF.
--    Si esto no devuelve dos filas con un JSON valido, validar un pedido acaba en
--    "array_key_exists(): Argument #2 ($array) must be of type array, null given"
--    y el cliente no puede terminar la compra.
SELECT `id_configuration`, `id_shop`, `name`, `value`
FROM `ps_configuration`
WHERE `name` IN ('PS_INVCE_INVOICE_ADDR_RULES', 'PS_INVCE_DELIVERY_ADDR_RULES');

-- 2. Claves multiidioma y cuantos idiomas tiene cada una.
--    Una clave con menos filas que idiomas tiene la tabla ps_lang tumba
--    Pedidos > Facturas con "Expected argument of type object, array or empty".
SELECT c.`name`,
       c.`id_configuration`,
       c.`id_shop`,
       (SELECT COUNT(*) FROM `ps_configuration_lang` cl
        WHERE cl.`id_configuration` = c.`id_configuration`) AS `filas_lang`,
       (SELECT COUNT(*) FROM `ps_lang`) AS `idiomas`
FROM `ps_configuration` c
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
   OR c.`id_configuration` IN (SELECT DISTINCT `id_configuration` FROM `ps_configuration_lang`)
ORDER BY `filas_lang` ASC, c.`name` ASC;

-- 3. Filas de configuration_lang sin su fila en configuration.
SELECT COUNT(*) AS `huerfanas`
FROM `ps_configuration_lang` cl
LEFT JOIN `ps_configuration` c ON c.`id_configuration` = cl.`id_configuration`
WHERE c.`id_configuration` IS NULL;

-- 4. Filas de configuration_lang de un idioma que ya no existe.
SELECT COUNT(*) AS `idiomas_muertos`
FROM `ps_configuration_lang` cl
LEFT JOIN `ps_lang` l ON l.`id_lang` = cl.`id_lang`
WHERE l.`id_lang` IS NULL;

-- 5. Idiomas de la tienda.
SELECT `id_lang`, `iso_code`, `language_code`, `active` FROM `ps_lang` ORDER BY `id_lang`;

-- 6. Tablas de traduccion de verdad (acaban en _lang y llevan id_lang en la clave
--    primaria) con el numero de filas por idioma. Las que no cuadran son las cojas.
SELECT s.TABLE_NAME
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.INDEX_NAME = 'PRIMARY'
  AND s.COLUMN_NAME = 'id_lang'
  AND s.TABLE_NAME LIKE 'ps\_%\_lang'
GROUP BY s.TABLE_NAME
ORDER BY s.TABLE_NAME;

-- 7. Genera la consulta de conteo por idioma de cada tabla _lang.
--    Copia el resultado y ejecutalo.
SELECT CONCAT(
         'SELECT ''', s.TABLE_NAME, ''' AS tabla, id_lang, COUNT(*) AS filas FROM `',
         s.TABLE_NAME, '` GROUP BY id_lang UNION ALL'
       ) AS `consulta`
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.INDEX_NAME = 'PRIMARY'
  AND s.COLUMN_NAME = 'id_lang'
  AND s.TABLE_NAME LIKE 'ps\_%\_lang'
GROUP BY s.TABLE_NAME
ORDER BY s.TABLE_NAME;

-- 8. Los overrides, desactivados o no.
SELECT `name`, `value` FROM `ps_configuration` WHERE `name` = 'PS_DISABLE_OVERRIDES';
