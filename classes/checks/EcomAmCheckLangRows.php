<?php
/**
 * Ecom Aftermigration - Filas que faltan en las tablas _lang
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Un idioma anadido despues de una migracion, o una migracion que trae solo una
 * parte de las filas, deja las tablas _lang cojas: productos sin nombre, categorias
 * en blanco, paginas CMS vacias y ObjectModel lanzando excepciones al cargar.
 *
 * La deteccion cuenta filas por idioma (barrido de indice, barato). La reparacion
 * copia las filas del idioma con mas datos a los que van cortos, con INSERT IGNORE,
 * asi que nunca pisa lo que ya hay traducido.
 *
 * Lo que NO hace: borrar. Las filas de idiomas que ya no existen solo se cuentan,
 * y el SQL para limpiarlas esta en sql/03-limpiar-filas-huerfanas.sql.
 */
class EcomAmCheckLangRows extends EcomAmCheck
{
    /** @var array tablas que lleva otro chequeo */
    private $excluidas = array('configuration_lang');

    /** @var array|null cache del analisis dentro de la misma peticion */
    private $cache = null;

    public function getCodigo()
    {
        return 'lang_rows';
    }

    public function getTitulo()
    {
        return $this->t('Rows missing in the language tables');
    }

    public function getDescripcion()
    {
        return $this->t('Products, categories or CMS pages with no row for one of the languages come out empty in the front office.');
    }

    /**
     * @return int tope de filas por tabla: por encima solo se informa
     */
    private function tope()
    {
        $tope = (int) Configuration::get('ECOM_AM_LANG_MAX');

        return $tope > 0 ? $tope : 200000;
    }

    /**
     * Solo tablas de traduccion de verdad: nombre que acaba en _lang Y con id_lang
     * dentro de la clave primaria.
     *
     * El filtro importa. Hay tablas con columna id_lang que NO son traducciones
     * (ps_customer, ps_employee, ps_search_word): copiar sus filas a otro idioma
     * duplicaria clientes.
     *
     * @return array
     */
    private function tablasLang()
    {
        // Nada de LIKE con el prefijo: si el prefijo lleva un guion bajo (ps_, y
        // lo lleva siempre) el LIKE lo toma como comodin. Se filtra en PHP.
        $filas = Db::getInstance()->executeS(
            "SELECT DISTINCT s.TABLE_NAME
             FROM INFORMATION_SCHEMA.STATISTICS s
             WHERE s.TABLE_SCHEMA = DATABASE()
               AND s.INDEX_NAME = 'PRIMARY'
               AND s.COLUMN_NAME = 'id_lang'
             ORDER BY s.TABLE_NAME"
        );

        $prefijo = _DB_PREFIX_;
        $largoPrefijo = Tools::strlen($prefijo);
        $tablas = array();

        foreach (is_array($filas) ? $filas : array() as $fila) {
            $nombre = (string) $fila['TABLE_NAME'];

            if (Tools::substr($nombre, 0, $largoPrefijo) !== $prefijo) {
                continue;
            }

            $corto = Tools::substr($nombre, $largoPrefijo);

            if (Tools::substr($corto, -5) !== '_lang' || $corto === '_lang') {
                continue;
            }

            if (in_array($corto, $this->excluidas, true)) {
                continue;
            }

            $tablas[] = $nombre;
        }

        return $tablas;
    }

    /**
     * @param string $tabla
     *
     * @return array nombres de columna en el orden de la tabla
     */
    private function columnas($tabla)
    {
        $filas = Db::getInstance()->executeS(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($tabla) . "'
             ORDER BY ORDINAL_POSITION"
        );

        $columnas = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $columnas[] = (string) $fila['COLUMN_NAME'];
        }

        return $columnas;
    }

    /**
     * @return array
     */
    private function analizar()
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $db = Db::getInstance();
        $idiomas = $this->idiomas();
        $tope = $this->tope();

        $cojas = array();
        $grandes = array();
        $huerfanas = 0;

        foreach ($this->tablasLang() as $tabla) {
            $conteos = $db->executeS(
                'SELECT `id_lang`, COUNT(*) AS n FROM `' . bqSQL($tabla) . '` GROUP BY `id_lang`'
            );

            $porIdioma = array();
            $total = 0;
            foreach (is_array($conteos) ? $conteos : array() as $fila) {
                $idLang = (int) $fila['id_lang'];
                $n = (int) $fila['n'];
                $porIdioma[$idLang] = $n;
                $total += $n;

                if (!in_array($idLang, $idiomas, true)) {
                    $huerfanas += $n;
                }
            }

            if ($total === 0) {
                continue;
            }

            $referencia = 0;
            $maximo = 0;
            foreach ($idiomas as $idLang) {
                $n = isset($porIdioma[$idLang]) ? $porIdioma[$idLang] : 0;
                if ($n > $maximo) {
                    $maximo = $n;
                    $referencia = $idLang;
                }
            }

            if (!$maximo) {
                continue;
            }

            $faltan = array();
            foreach ($idiomas as $idLang) {
                $n = isset($porIdioma[$idLang]) ? $porIdioma[$idLang] : 0;
                if ($n < $maximo) {
                    $faltan[$idLang] = $maximo - $n;
                }
            }

            if (!count($faltan)) {
                continue;
            }

            if ($total > $tope) {
                $grandes[] = array('tabla' => $tabla, 'filas' => $total, 'faltan' => $faltan);
                continue;
            }

            $cojas[] = array(
                'tabla' => $tabla,
                'referencia' => $referencia,
                'faltan' => $faltan,
            );
        }

        $this->cache = array(
            'cojas' => $cojas,
            'grandes' => $grandes,
            'huerfanas' => $huerfanas,
        );

        return $this->cache;
    }

    public function comprobar()
    {
        $analisis = $this->analizar();

        if (!count($analisis['cojas']) && !count($analisis['grandes']) && !$analisis['huerfanas']) {
            return $this->resultado(
                self::ESTADO_OK,
                $this->t('Every language table has the same number of rows for every language.')
            );
        }

        $detalle = array();
        foreach ($analisis['cojas'] as $coja) {
            $trozos = array();
            foreach ($coja['faltan'] as $idLang => $n) {
                $trozos[] = 'id_lang ' . $idLang . ': ' . $n;
            }
            $detalle[] = $coja['tabla'] . ' -> ' . implode(', ', $trozos);
        }

        foreach ($analisis['grandes'] as $grande) {
            $detalle[] = $this->t(
                '%table% has %rows% rows: only reported, repair it by hand or raise the limit.',
                array('%table%' => $grande['tabla'], '%rows%' => $grande['filas'])
            );
        }

        if ($analisis['huerfanas']) {
            $detalle[] = $this->t(
                '%count% row(s) belong to a language that no longer exists. They are only reported, the SQL to remove them is in sql/03-limpiar-filas-huerfanas.sql.',
                array('%count%' => $analisis['huerfanas'])
            );
        }

        $estado = count($analisis['cojas']) ? self::ESTADO_FALLO : self::ESTADO_AVISO;

        return $this->resultado(
            $estado,
            $this->t('%count% table(s) do not have the same rows in every language.', array(
                '%count%' => count($analisis['cojas']) + count($analisis['grandes']),
            )),
            $detalle
        );
    }

    public function puedeArreglar()
    {
        $analisis = $this->analizar();

        return count($analisis['cojas']) > 0;
    }

    public function arreglar()
    {
        $db = Db::getInstance();
        $analisis = $this->analizar();
        $detalle = array();
        $insertadas = 0;

        foreach ($analisis['cojas'] as $coja) {
            $tabla = $coja['tabla'];
            $columnas = $this->columnas($tabla);

            if (!count($columnas) || !in_array('id_lang', $columnas, true)) {
                continue;
            }

            $lista = array();
            foreach ($columnas as $columna) {
                $lista[] = '`' . bqSQL($columna) . '`';
            }

            foreach ($coja['faltan'] as $idLang => $cuantas) {
                $seleccion = array();
                foreach ($columnas as $columna) {
                    $seleccion[] = $columna === 'id_lang'
                        ? (int) $idLang
                        : '`' . bqSQL($columna) . '`';
                }

                $sql = 'INSERT IGNORE INTO `' . bqSQL($tabla) . '` (' . implode(', ', $lista) . ')'
                    . ' SELECT ' . implode(', ', $seleccion)
                    . ' FROM `' . bqSQL($tabla) . '`'
                    . ' WHERE `id_lang` = ' . (int) $coja['referencia'];

                if ($db->execute($sql)) {
                    $insertadas += (int) $cuantas;
                    $detalle[] = $tabla . ' -> id_lang ' . (int) $idLang;
                } else {
                    $detalle[] = $tabla . ' -> id_lang ' . (int) $idLang . ' (ERROR: ' . $db->getMsgError() . ')';
                }
            }
        }

        $this->cache = null;
        $despues = $this->analizar();
        $quedan = count($despues['cojas']);

        return $this->arreglado(
            $quedan === 0,
            $quedan === 0
                ? $this->t('About %count% row(s) copied from the reference language.', array('%count%' => $insertadas))
                : $this->t('%count% table(s) are still short after the repair.', array('%count%' => $quedan)),
            $detalle
        );
    }
}
