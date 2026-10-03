<?php
/**
 * Ecom Aftermigration - Clase base de un chequeo
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Un chequeo detecta un fallo concreto que deja una migracion y, si sabe, lo arregla.
 *
 * comprobar() NO escribe nunca en la base de datos. arreglar() solo se llama
 * cuando el usuario pulsa el boton.
 */
abstract class EcomAmCheck
{
    const ESTADO_OK = 'ok';
    const ESTADO_AVISO = 'aviso';
    const ESTADO_FALLO = 'fallo';

    /** @var Module */
    protected $modulo;

    /**
     * @param Module $modulo
     */
    public function __construct($modulo)
    {
        $this->modulo = $modulo;
    }

    /**
     * Identificador corto y estable del chequeo.
     *
     * @return string
     */
    abstract public function getCodigo();

    /**
     * @return string
     */
    abstract public function getTitulo();

    /**
     * Una frase. El detalle largo va al manual, no a la pantalla.
     *
     * @return string
     */
    abstract public function getDescripcion();

    /**
     * @return array array('estado' => ..., 'mensaje' => ..., 'detalle' => array())
     */
    abstract public function comprobar();

    /**
     * @return bool
     */
    public function puedeArreglar()
    {
        return true;
    }

    /**
     * @return array array('ok' => bool, 'mensaje' => string, 'detalle' => array())
     */
    abstract public function arreglar();

    /**
     * @param string $texto
     * @param array $parametros
     *
     * @return string
     */
    protected function t($texto, array $parametros = array())
    {
        return $this->modulo->trans($texto, $parametros, 'Modules.Ecomaftermigration.Admin');
    }

    /**
     * @param string $estado
     * @param string $mensaje
     * @param array $detalle
     *
     * @return array
     */
    protected function resultado($estado, $mensaje, array $detalle = array())
    {
        return array(
            'estado' => $estado,
            'mensaje' => $mensaje,
            'detalle' => $detalle,
        );
    }

    /**
     * @param bool $ok
     * @param string $mensaje
     * @param array $detalle
     *
     * @return array
     */
    protected function arreglado($ok, $mensaje, array $detalle = array())
    {
        EcomAmLog::escribir('[' . $this->getCodigo() . '] ' . ($ok ? 'OK' : 'FALLO') . ' ' . $mensaje);

        return array(
            'ok' => (bool) $ok,
            'mensaje' => $mensaje,
            'detalle' => $detalle,
        );
    }

    /**
     * Todos los id_lang de la tienda, activos o no: es lo que recorre
     * PrestaShop\PrestaShop\Adapter\Configuration::getLocalized().
     *
     * @return array
     */
    protected function idiomas()
    {
        $filas = Db::getInstance()->executeS('SELECT `id_lang` FROM `' . _DB_PREFIX_ . 'lang`');

        $ids = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $ids[] = (int) $fila['id_lang'];
        }

        return $ids;
    }

    /**
     * @return int
     */
    protected function idiomaPorDefecto()
    {
        $id = (int) Configuration::get('PS_LANG_DEFAULT');
        if ($id > 0) {
            return $id;
        }

        $idiomas = $this->idiomas();

        return count($idiomas) ? (int) $idiomas[0] : 1;
    }
}
