<?php
/**
 * Ecom Aftermigration - Arrays cazados por el override de Db
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * El override de Db evita el fatal, pero el fallo de verdad esta en el modulo que
 * manda un array donde va una cadena. Este chequeo ensena lo que ha quedado en
 * logs/escape.log: fichero y linea del culpable.
 *
 * El fatal original es:
 *
 *     TypeError: strip_tags(): Argument #1 ($string) must be of type string, array given
 *     in classes/db/Db.php on line 795
 *
 * El recorrido es pSQL() -> DbCore::escape() -> DbPDOCore::_escape(), que con un
 * array devuelve un array (str_replace lo permite), y strip_tags() ya no.
 */
class EcomAmCheckEscapeArrays extends EcomAmCheck
{
    public function getCodigo()
    {
        return 'escape_arrays';
    }

    public function getTitulo()
    {
        return $this->t('Arrays reaching pSQL()');
    }

    public function getDescripcion()
    {
        return $this->t('Each entry is a module writing a field wrong. The override saves the page, the module still has to be fixed.');
    }

    public function comprobar()
    {
        $cuantas = EcomAmLog::contarIncidenciasEscape();

        if (!$cuantas) {
            return $this->resultado(
                self::ESTADO_OK,
                $this->t('No array has reached pSQL() so far.')
            );
        }

        return $this->resultado(
            self::ESTADO_AVISO,
            $this->t('%count% incident(s) recorded. Look at the file and the line: that is the module to fix.', array('%count%' => $cuantas)),
            EcomAmLog::ultimasLineasEscape(15)
        );
    }

    public function puedeArreglar()
    {
        return false;
    }

    public function arreglar()
    {
        return $this->arreglado(
            false,
            $this->t('This one is not repaired from here: the module that sends the array has to be corrected.')
        );
    }
}
