<?php
/**
 * Ecom Aftermigration - Estado de los overrides
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Comprueba que los dos overrides estan puestos, que son los de esta version del
 * modulo y que PrestaShop no los esta ignorando.
 */
class EcomAmCheckOverrides extends EcomAmCheck
{
    public function getCodigo()
    {
        return 'overrides';
    }

    public function getTitulo()
    {
        return $this->t('Protection overrides');
    }

    public function getDescripcion()
    {
        return $this->t('Db::escape() and AddressFormat::generateAddress() stop being fatal errors under PHP 8.');
    }

    public function comprobar()
    {
        $estado = EcomAmOverrides::estado();
        $detalle = array();
        $pendientes = 0;
        $ajenos = 0;

        foreach ($estado['ficheros'] as $fichero) {
            switch ($fichero['estado']) {
                case EcomAmOverrides::ESTADO_INSTALADO:
                    $detalle[] = $fichero['relativo'] . ': ' . $this->t('installed');
                    break;
                case EcomAmOverrides::ESTADO_ANTIGUO:
                    ++$pendientes;
                    $detalle[] = $fichero['relativo'] . ': ' . $this->t('an older version is installed');
                    break;
                case EcomAmOverrides::ESTADO_AJENO:
                    ++$ajenos;
                    $detalle[] = $fichero['relativo'] . ': ' . $this->t('there is another override in place, merge it by hand');
                    break;
                default:
                    ++$pendientes;
                    $detalle[] = $fichero['relativo'] . ': ' . $this->t('not installed');
            }
        }

        if ($estado['desactivados']) {
            $detalle[] = $this->t('PrestaShop is ignoring every override (Advanced parameters > Performance).');

            return $this->resultado(
                self::ESTADO_FALLO,
                $this->t('Overrides are disabled in this shop: they will not protect anything.'),
                $detalle
            );
        }

        if ($ajenos) {
            return $this->resultado(
                self::ESTADO_AVISO,
                $this->t('%count% override file(s) belong to someone else and have to be merged by hand.', array('%count%' => $ajenos)),
                $detalle
            );
        }

        if ($pendientes) {
            return $this->resultado(
                self::ESTADO_FALLO,
                $this->t('%count% override file(s) are missing or out of date.', array('%count%' => $pendientes)),
                $detalle
            );
        }

        return $this->resultado(
            self::ESTADO_OK,
            $this->t('Both overrides are installed and up to date.'),
            $detalle
        );
    }

    public function puedeArreglar()
    {
        return !EcomAmOverrides::overridesDesactivados();
    }

    public function arreglar()
    {
        $resultado = EcomAmOverrides::instalar();

        return $this->arreglado($resultado['ok'], $resultado['mensaje']);
    }
}
