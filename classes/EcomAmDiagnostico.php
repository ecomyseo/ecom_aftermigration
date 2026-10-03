<?php
/**
 * Ecom Aftermigration - Registro y ejecucion de los chequeos
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/checks/EcomAmCheckPdfAddrRules.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/checks/EcomAmCheckConfigLang.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/checks/EcomAmCheckOrderPagination.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/checks/EcomAmCheckLangRows.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/checks/EcomAmCheckOverrides.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/checks/EcomAmCheckEscapeArrays.php';

class EcomAmDiagnostico
{
    /** @var Module */
    private $modulo;

    /** @var EcomAmCheck[] */
    private $checks = array();

    /**
     * @param Module $modulo
     */
    public function __construct($modulo)
    {
        $this->modulo = $modulo;

        $this->checks = array(
            new EcomAmCheckPdfAddrRules($modulo),
            new EcomAmCheckConfigLang($modulo),
            new EcomAmCheckOrderPagination($modulo),
            new EcomAmCheckLangRows($modulo),
            new EcomAmCheckOverrides($modulo),
            new EcomAmCheckEscapeArrays($modulo),
        );
    }

    /**
     * @return EcomAmCheck[]
     */
    public function getChecks()
    {
        return $this->checks;
    }

    /**
     * Pasa todos los chequeos. Ninguno escribe en la base de datos.
     *
     * @return array
     */
    public function comprobarTodo()
    {
        $resultados = array();

        foreach ($this->checks as $check) {
            try {
                $resultado = $check->comprobar();
            } catch (Throwable $e) {
                $resultado = array(
                    'estado' => EcomAmCheck::ESTADO_AVISO,
                    'mensaje' => $this->modulo->trans(
                        'The check could not be run: %error%',
                        array('%error%' => $e->getMessage()),
                        'Modules.Ecomaftermigration.Admin'
                    ),
                    'detalle' => array(),
                );
                EcomAmLog::escribir('[' . $check->getCodigo() . '] excepción al comprobar: ' . $e->getMessage());
            }

            $resultado['codigo'] = $check->getCodigo();
            $resultado['titulo'] = $check->getTitulo();
            $resultado['descripcion'] = $check->getDescripcion();
            $resultado['puede_arreglar'] = $check->puedeArreglar()
                && $resultado['estado'] !== EcomAmCheck::ESTADO_OK;

            $resultados[] = $resultado;
        }

        return $resultados;
    }

    /**
     * @param string $codigo
     *
     * @return array
     */
    public function arreglar($codigo)
    {
        foreach ($this->checks as $check) {
            if ($check->getCodigo() !== $codigo) {
                continue;
            }

            if (!$check->puedeArreglar()) {
                return array(
                    'ok' => false,
                    'mensaje' => $this->modulo->trans(
                        'This check has to be fixed by hand.',
                        array(),
                        'Modules.Ecomaftermigration.Admin'
                    ),
                    'detalle' => array(),
                );
            }

            try {
                return $check->arreglar();
            } catch (Throwable $e) {
                EcomAmLog::escribir('[' . $codigo . '] excepción al arreglar: ' . $e->getMessage());

                return array(
                    'ok' => false,
                    'mensaje' => $this->modulo->trans(
                        'The repair failed: %error%',
                        array('%error%' => $e->getMessage()),
                        'Modules.Ecomaftermigration.Admin'
                    ),
                    'detalle' => array(),
                );
            }
        }

        return array(
            'ok' => false,
            'mensaje' => $this->modulo->trans(
                'Unknown check.',
                array(),
                'Modules.Ecomaftermigration.Admin'
            ),
            'detalle' => array(),
        );
    }
}
