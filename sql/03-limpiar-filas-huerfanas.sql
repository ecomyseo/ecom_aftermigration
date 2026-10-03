-- ---------------------------------------------------------------------------
-- Ecom Aftermigration - Limpieza de filas huerfanas (ESTO BORRA)
--
-- Ni el modulo ni arreglar.php ejecutan nada de este fichero. Va aparte a
-- proposito: borrar filas no se hace pulsando un boton sin haber mirado antes
-- lo que se lleva por delante.
--
-- Orden: primero los SELECT de arriba, se mira el numero, y solo entonces el
-- DELETE de debajo. Y con una copia de la base de datos hecha.
--
-- Cambia el prefijo ps_ por el de la tienda.
--
-- @author    Ecom Experts <ecomyseo@gmail.com>
-- @copyright 2026 Ecom Experts
-- @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
-- ---------------------------------------------------------------------------

-- ============================ MIRAR PRIMERO ================================

-- Cuantas filas de configuration_lang no tienen su fila en configuration.
SELECT COUNT(*) AS `huerfanas`
FROM `ps_configuration_lang` cl
LEFT JOIN `ps_configuration` c ON c.`id_configuration` = cl.`id_configuration`
WHERE c.`id_configuration` IS NULL;

-- Cuantas filas de configuration_lang son de un idioma que ya no existe.
SELECT cl.`id_lang`, COUNT(*) AS `filas`
FROM `ps_configuration_lang` cl
LEFT JOIN `ps_lang` l ON l.`id_lang` = cl.`id_lang`
WHERE l.`id_lang` IS NULL
GROUP BY cl.`id_lang`;

-- Genera un DELETE por cada tabla _lang con filas de idiomas que ya no existen.
-- Copia el resultado, MIRALO, y ejecuta solo lo que quieras.
SELECT CONCAT(
         'DELETE t FROM `', s.TABLE_NAME, '` t ',
         'LEFT JOIN `ps_lang` l ON l.id_lang = t.id_lang ',
         'WHERE l.id_lang IS NULL;'
       ) AS `sentencia`
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.INDEX_NAME = 'PRIMARY'
  AND s.COLUMN_NAME = 'id_lang'
  AND s.TABLE_NAME LIKE 'ps\_%\_lang'
GROUP BY s.TABLE_NAME
ORDER BY s.TABLE_NAME;

-- Y el recuento previo de cada una, para saber cuanto se va a borrar.
SELECT CONCAT(
         'SELECT ''', s.TABLE_NAME, ''' AS tabla, COUNT(*) AS a_borrar FROM `', s.TABLE_NAME,
         '` t LEFT JOIN `ps_lang` l ON l.id_lang = t.id_lang WHERE l.id_lang IS NULL UNION ALL'
       ) AS `consulta`
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.INDEX_NAME = 'PRIMARY'
  AND s.COLUMN_NAME = 'id_lang'
  AND s.TABLE_NAME LIKE 'ps\_%\_lang'
GROUP BY s.TABLE_NAME
ORDER BY s.TABLE_NAME;

-- ============================ BORRAR DESPUES ===============================
-- Quita el comentario de las dos sentencias cuando hayas mirado los recuentos.

-- DELETE cl FROM `ps_configuration_lang` cl
-- LEFT JOIN `ps_configuration` c ON c.`id_configuration` = cl.`id_configuration`
-- WHERE c.`id_configuration` IS NULL;

-- DELETE cl FROM `ps_configuration_lang` cl
-- LEFT JOIN `ps_lang` l ON l.`id_lang` = cl.`id_lang`
-- WHERE l.`id_lang` IS NULL;
