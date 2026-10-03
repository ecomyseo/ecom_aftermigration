<?php
/**
 * Ecom Aftermigration - Arreglos posteriores a una actualizacion o migracion de PrestaShop
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/EcomAmLog.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/EcomAmCheck.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/EcomAmOverrides.php';
require_once _PS_MODULE_DIR_ . 'ecom_aftermigration/classes/EcomAmDiagnostico.php';

class Ecom_Aftermigration extends Module
{
    const CLAVE_DEBUG = 'ECOM_AM_DEBUG';
    const CLAVE_ESCAPE_MODO = 'ECOM_AM_ESCAPE_MODO';
    const CLAVE_ESCAPE_LOG = 'ECOM_AM_ESCAPE_LOG';
    const CLAVE_LANG_MAX = 'ECOM_AM_LANG_MAX';
    const CLAVE_VERSION_DATOS = 'ECOM_AM_VERSION_DATOS';

    /** @var string dominio de traduccion del modulo */
    const DOMINIO = 'Modules.Ecomaftermigration.Admin';

    /** @var array mensajes que se pintan arriba de la pantalla */
    private $avisos = array();

    public function __construct()
    {
        $this->name = 'ecom_aftermigration';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Ecom Experts';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '';

        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->trans('After migration fixes', array(), self::DOMINIO);
        $this->description = $this->trans(
            'Detects and repairs the breakages that an upgrade or a migration leaves behind: PDF address rules, multilanguage configuration, missing language rows and PHP 8 fatal errors.',
            array(),
            self::DOMINIO
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling does not undo the repairs already applied. The overrides, if installed, stay in place.',
            array(),
            self::DOMINIO
        );
    }

    /**
     * Module::trans() es protected en el nucleo (classes/module/Module.php).
     * Se amplia la visibilidad con la firma exacta para poder llamarlo desde
     * las clases de classes/ y desde las plantillas.
     *
     * @param string $id
     * @param array $parameters
     * @param string|null $domain
     * @param string|null $locale
     *
     * @return string
     */
    public function trans($id, array $parameters = array(), $domain = null, $locale = null)
    {
        return parent::trans($id, $parameters, $domain, $locale);
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $this->prepararCarpetaLogs();

        Configuration::updateValue(self::CLAVE_DEBUG, 0);
        Configuration::updateValue(self::CLAVE_ESCAPE_MODO, EcomAmLog::ESCAPE_MODO_IDIOMA);
        Configuration::updateValue(self::CLAVE_ESCAPE_LOG, 1);
        Configuration::updateValue(self::CLAVE_LANG_MAX, 200000);
        Configuration::updateValue(self::CLAVE_VERSION_DATOS, $this->version);

        // Deja escrito logs/escape.conf, que es lo unico que lee el override de Db.
        EcomAmLog::sincronizarBandera();

        EcomAmLog::escribir('Módulo instalado, versión ' . $this->version);

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CLAVE_DEBUG);
        Configuration::deleteByName(self::CLAVE_ESCAPE_MODO);
        Configuration::deleteByName(self::CLAVE_ESCAPE_LOG);
        Configuration::deleteByName(self::CLAVE_LANG_MAX);
        Configuration::deleteByName(self::CLAVE_VERSION_DATOS);

        EcomAmLog::quitarBandera();

        return parent::uninstall();
    }

    /**
     * Migraciones y altas de opciones nuevas. Se ejecuta al abrir la configuracion,
     * en lugar de ficheros upgrade-*.php.
     */
    private function addnewfeatures()
    {
        try {
            if (Configuration::get(self::CLAVE_VERSION_DATOS) === $this->version) {
                return;
            }

            $this->prepararCarpetaLogs();

            if (!Configuration::hasKey(self::CLAVE_ESCAPE_MODO)) {
                Configuration::updateValue(self::CLAVE_ESCAPE_MODO, EcomAmLog::ESCAPE_MODO_IDIOMA);
            }
            if (!Configuration::hasKey(self::CLAVE_ESCAPE_LOG)) {
                Configuration::updateValue(self::CLAVE_ESCAPE_LOG, 1);
            }
            if (!Configuration::hasKey(self::CLAVE_LANG_MAX)) {
                Configuration::updateValue(self::CLAVE_LANG_MAX, 200000);
            }
            if (!Configuration::hasKey(self::CLAVE_DEBUG)) {
                Configuration::updateValue(self::CLAVE_DEBUG, 0);
            }

            Configuration::updateValue(self::CLAVE_VERSION_DATOS, $this->version);
            EcomAmLog::escribir('addnewfeatures() ejecutado, version de datos ' . $this->version);
        } catch (Throwable $e) {
            EcomAmLog::escribir('addnewfeatures() ha fallado: ' . $e->getMessage());
        }
    }

    /**
     * Crea logs/ con su index.php y su .htaccess si no existen.
     */
    private function prepararCarpetaLogs()
    {
        $dir = _PS_MODULE_DIR_ . $this->name . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        EcomAmLog::protegerCarpeta();
    }

    public function getContent()
    {
        $this->addnewfeatures();

        $salida = '';

        if ($this->comprobarActualizacionGmartos()) {
            $this->context->smarty->assign(array(
                'gmartos_latest_version' => Configuration::get('GMARTOS_LATEST_VERSION_' . $this->name),
            ));
            $salida .= $this->display(__FILE__, 'views/templates/admin/gmartos_update.tpl');
        }

        $this->postProcess();

        $diagnostico = new EcomAmDiagnostico($this);
        $resultados = $diagnostico->comprobarTodo();

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        $salida .= $this->renderPanel($resultados, $diagnostico);
        $salida .= $this->renderForm();

        return $salida;
    }

    /**
     * Lee los botones y el formulario de la pantalla.
     */
    private function postProcess()
    {
        if (Tools::isSubmit('submitEcomAmConfig')) {
            Configuration::updateValue(self::CLAVE_DEBUG, (int) Tools::getValue(self::CLAVE_DEBUG));
            Configuration::updateValue(self::CLAVE_ESCAPE_LOG, (int) Tools::getValue(self::CLAVE_ESCAPE_LOG));

            $modo = Tools::getValue(self::CLAVE_ESCAPE_MODO);
            if (!in_array($modo, EcomAmLog::modosEscapeValidos(), true)) {
                $modo = EcomAmLog::ESCAPE_MODO_IDIOMA;
            }
            Configuration::updateValue(self::CLAVE_ESCAPE_MODO, $modo);

            $max = (int) Tools::getValue(self::CLAVE_LANG_MAX);
            Configuration::updateValue(self::CLAVE_LANG_MAX, $max > 0 ? $max : 200000);

            EcomAmLog::sincronizarBandera();

            $this->avisos[] = array(
                'tipo' => 'success',
                'texto' => $this->trans('Settings saved.', array(), self::DOMINIO),
            );
        }

        if (Tools::isSubmit('ecomamArreglar')) {
            $codigo = (string) Tools::getValue('ecomamCheck');
            $diagnostico = new EcomAmDiagnostico($this);
            $resultado = $diagnostico->arreglar($codigo);
            $this->avisos[] = array(
                'tipo' => $resultado['ok'] ? 'success' : 'danger',
                'texto' => $resultado['mensaje'],
            );
        }

        if (Tools::isSubmit('ecomamOverridesInstalar')) {
            $resultado = EcomAmOverrides::instalar();
            $this->avisos[] = array(
                'tipo' => $resultado['ok'] ? 'success' : 'danger',
                'texto' => $resultado['mensaje'],
            );
        }

        if (Tools::isSubmit('ecomamOverridesQuitar')) {
            $resultado = EcomAmOverrides::quitar();
            $this->avisos[] = array(
                'tipo' => $resultado['ok'] ? 'success' : 'danger',
                'texto' => $resultado['mensaje'],
            );
        }

        if (Tools::isSubmit('ecomamVaciarLog')) {
            EcomAmLog::vaciar();
            $this->avisos[] = array(
                'tipo' => 'success',
                'texto' => $this->trans('Log files emptied.', array(), self::DOMINIO),
            );
        }
    }

    /**
     * @param array $resultados
     * @param EcomAmDiagnostico $diagnostico
     *
     * @return string
     */
    private function renderPanel($resultados, EcomAmDiagnostico $diagnostico)
    {
        $fallos = 0;
        $avisosCheck = 0;
        foreach ($resultados as $resultado) {
            if ($resultado['estado'] === EcomAmCheck::ESTADO_FALLO) {
                ++$fallos;
            } elseif ($resultado['estado'] === EcomAmCheck::ESTADO_AVISO) {
                ++$avisosCheck;
            }
        }

        $this->context->smarty->assign(array(
            'ecomam_avisos' => $this->avisos,
            'ecomam_resultados' => $resultados,
            'ecomam_fallos' => $fallos,
            'ecomam_avisos_check' => $avisosCheck,
            'ecomam_overrides' => EcomAmOverrides::estado(),
            'ecomam_log_lineas' => EcomAmLog::ultimasLineas(40),
            'ecomam_escape_lineas' => EcomAmLog::ultimasLineasEscape(40),
            'ecomam_form_action' => $this->contextoUrl(),
            'ecomam_ps_version' => _PS_VERSION_,
            'ecomam_php_version' => PHP_VERSION,
            'ecomam_multitienda' => (bool) Shop::isFeatureActive(),
            'ecomam_total_checks' => count($resultados),
        ));

        unset($diagnostico);

        return $this->display(__FILE__, 'views/templates/admin/panel.tpl');
    }

    /**
     * URL de la pantalla actual, con su token, para los formularios de los botones.
     *
     * @return string
     */
    private function contextoUrl()
    {
        return AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getValue('token');
    }

    private function getConfigFormValues()
    {
        return array(
            self::CLAVE_DEBUG => (int) Configuration::get(self::CLAVE_DEBUG),
            self::CLAVE_ESCAPE_LOG => (int) Configuration::get(self::CLAVE_ESCAPE_LOG),
            self::CLAVE_ESCAPE_MODO => (string) Configuration::get(self::CLAVE_ESCAPE_MODO),
            self::CLAVE_LANG_MAX => (int) Configuration::get(self::CLAVE_LANG_MAX),
        );
    }

    /**
     * UN SOLO HelperForm con pestanas. Las claves llevan el prefijo "am" para no
     * chocar con ids que ya existen en el back-office (content, general...).
     *
     * @return string
     */
    private function renderForm()
    {
        $fields_form = array();

        $fields_form[0]['form'] = array(
            'tinymce' => false,
            'legend' => array(
                'title' => $this->trans('Settings', array(), self::DOMINIO),
                'icon' => 'icon-cogs',
            ),
            'tabs' => array(
                'amcorreccion' => $this->trans('Repairs', array(), self::DOMINIO),
                'amregistro' => $this->trans('Logs', array(), self::DOMINIO),
            ),
            'input' => array(
                array(
                    'type' => 'select',
                    'tab' => 'amcorreccion',
                    'label' => $this->trans('Array passed to pSQL()', array(), self::DOMINIO),
                    'name' => self::CLAVE_ESCAPE_MODO,
                    'desc' => $this->trans('What the Db override does when a module sends an array where a string is expected.', array(), self::DOMINIO),
                    'options' => array(
                        'query' => array(
                            array(
                                'id' => EcomAmLog::ESCAPE_MODO_IDIOMA,
                                'name' => $this->trans('Keep the value of the default language (recommended)', array(), self::DOMINIO),
                            ),
                            array(
                                'id' => EcomAmLog::ESCAPE_MODO_UNIR,
                                'name' => $this->trans('Join the values with commas', array(), self::DOMINIO),
                            ),
                            array(
                                'id' => EcomAmLog::ESCAPE_MODO_VACIAR,
                                'name' => $this->trans('Store an empty value', array(), self::DOMINIO),
                            ),
                        ),
                        'id' => 'id',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'text',
                    'tab' => 'amcorreccion',
                    'label' => $this->trans('Row limit per language table', array(), self::DOMINIO),
                    'name' => self::CLAVE_LANG_MAX,
                    'class' => 'fixed-width-md',
                    'desc' => $this->trans('Tables with more rows than this are only reported, never repaired automatically.', array(), self::DOMINIO),
                ),
                array(
                    'type' => 'switch',
                    'tab' => 'amregistro',
                    'label' => $this->trans('Record the arrays caught by the override', array(), self::DOMINIO),
                    'name' => self::CLAVE_ESCAPE_LOG,
                    'is_bool' => true,
                    'desc' => $this->trans('Writes the offending file and line to logs/escape.log. This is what tells you which module has to be fixed.', array(), self::DOMINIO),
                    'values' => array(
                        array('id' => 'escape_log_on', 'value' => 1, 'label' => $this->trans('Yes', array(), self::DOMINIO)),
                        array('id' => 'escape_log_off', 'value' => 0, 'label' => $this->trans('No', array(), self::DOMINIO)),
                    ),
                ),
                array(
                    'type' => 'switch',
                    'tab' => 'amregistro',
                    'label' => $this->trans('Debug mode', array(), self::DOMINIO),
                    'name' => self::CLAVE_DEBUG,
                    'is_bool' => true,
                    'desc' => $this->trans('Writes every check and every repair to logs/ecom_aftermigration.log.', array(), self::DOMINIO),
                    'values' => array(
                        array('id' => 'debug_on', 'value' => 1, 'label' => $this->trans('Yes', array(), self::DOMINIO)),
                        array('id' => 'debug_off', 'value' => 0, 'label' => $this->trans('No', array(), self::DOMINIO)),
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->trans('Save', array(), self::DOMINIO),
                'class' => 'btn btn-default pull-right',
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitEcomAmConfig';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm($fields_form);
    }

    // <cadenas-extractor>
    /**
     * Las cadenas que se traducen desde otras clases (EcomAmCheck::t(),
     * EcomAmOverrides::t()) llegan a trans() dentro de una variable, y el
     * extractor de PrestaShop solo ve literales. Se declaran aqui para que
     * las encuentre. Este metodo NO lo llama nadie a proposito.
     *
     * @return void
     */
    public function cadenasParaElExtractor()
    {
        $this->trans('%count% check(s) failed. Press Repair on each one.', array(), self::DOMINIO);
        $this->trans('%count% configuration key(s) are missing or do not hold valid JSON. Validating an order will end in a fatal error.', array(), self::DOMINIO);
        $this->trans('%count% incident(s) recorded. Look at the file and the line: that is the module to fix.', array(), self::DOMINIO);
        $this->trans('%count% key(s) are still wrong after the repair.', array(), self::DOMINIO);
        $this->trans('%count% key(s) restored to the factory value.', array(), self::DOMINIO);
        $this->trans('%count% orphan row(s) in configuration_lang.', array(), self::DOMINIO);
        $this->trans('%count% override file(s) are missing or out of date.', array(), self::DOMINIO);
        $this->trans('%count% override file(s) belong to someone else and have to be merged by hand.', array(), self::DOMINIO);
        $this->trans('%count% row(s) are missing in configuration_lang.', array(), self::DOMINIO);
        $this->trans('%count% row(s) are still missing after the repair.', array(), self::DOMINIO);
        $this->trans('%count% row(s) belong to a language that no longer exists.', array(), self::DOMINIO);
        $this->trans('%count% row(s) belong to a language that no longer exists. They are only reported, the SQL to remove them is in sql/03-limpiar-filas-huerfanas.sql.', array(), self::DOMINIO);
        $this->trans('%count% row(s) inserted in configuration_lang.', array(), self::DOMINIO);
        $this->trans('%count% table(s) are still short after the repair.', array(), self::DOMINIO);
        $this->trans('%count% table(s) do not have the same rows in every language.', array(), self::DOMINIO);
        $this->trans('%file% could not be copied. Check the write permissions of the override/ folder.', array(), self::DOMINIO);
        $this->trans('%table% has %rows% rows: only reported, repair it by hand or raise the limit.', array(), self::DOMINIO);
        $this->trans('A key without rows in configuration_lang brings down Orders > Invoices and the other forms that use translatable fields.', array(), self::DOMINIO);
        $this->trans('About %count% row(s) copied from the reference language.', array(), self::DOMINIO);
        $this->trans('Address rules of the invoice PDF', array(), self::DOMINIO);
        $this->trans('After installing the overrides', array(), self::DOMINIO);
        $this->trans('After migration fixes', array(), self::DOMINIO);
        $this->trans('Array passed to pSQL()', array(), self::DOMINIO);
        $this->trans('Arrays reaching pSQL()', array(), self::DOMINIO);
        $this->trans('Both keys hold valid JSON in every shop.', array(), self::DOMINIO);
        $this->trans('Both overrides are installed and up to date.', array(), self::DOMINIO);
        $this->trans('Check', array(), self::DOMINIO);
        $this->trans('Check again', array(), self::DOMINIO);
        $this->trans('Configuration::loadConfiguration() decides whether a key is multilanguage by looking at configuration_lang. A key with no rows there stops being multilanguage, the adapter returns a string, and the translatable field of the form gets a string instead of an array: Orders > Invoices dies with Expected argument of type "object, array or empty". The repair inserts the missing rows.', array(), self::DOMINIO);
        $this->trans('Copied %count% file(s). %files% was left untouched because an override that does not belong to this module is already there: it has to be merged by hand.', array(), self::DOMINIO);
        $this->trans('Db::escape() and AddressFormat::generateAddress() stop being fatal errors under PHP 8.', array(), self::DOMINIO);
        $this->trans('Db::escape() ends in strip_tags(), and under PHP 8 an array there is a fatal error. It is always a module saving an ObjectModel field with an array. The override keeps the page alive and writes down the file and the line: that is the module that has to be corrected.', array(), self::DOMINIO);
        $this->trans('Debug mode', array(), self::DOMINIO);
        $this->trans('Detects and repairs the breakages that an upgrade or a migration leaves behind: PDF address rules, multilanguage configuration, missing language rows and PHP 8 fatal errors.', array(), self::DOMINIO);
        $this->trans('Diagnosis', array(), self::DOMINIO);
        $this->trans('Each entry is a module writing a field wrong. The override saves the page, the module still has to be fixed.', array(), self::DOMINIO);
        $this->trans('Empty the logs', array(), self::DOMINIO);
        $this->trans('Every check passed.', array(), self::DOMINIO);
        $this->trans('Every language table has the same number of rows for every language.', array(), self::DOMINIO);
        $this->trans('Every multilanguage key has one row per language.', array(), self::DOMINIO);
        $this->trans('Failure', array(), self::DOMINIO);
        $this->trans('Get in touch with support to update it.', array(), self::DOMINIO);
        $this->trans('HTMLTemplateInvoice reads PS_INVCE_INVOICE_ADDR_RULES and PS_INVCE_DELIVERY_ADDR_RULES and hands the result straight to AddressFormat::generateAddress(). If the key is gone, json_decode returns null and PHP 8 throws array_key_exists(): Argument #2 must be of type array, null given. The invoice is generated inside validateOrder(), so the customer cannot finish the purchase. The repair writes the factory value back into both keys.', array(), self::DOMINIO);
        $this->trans('Help / how it works', array(), self::DOMINIO);
        $this->trans('Install the overrides', array(), self::DOMINIO);
        $this->trans('Join the values with commas', array(), self::DOMINIO);
        $this->trans('Keep the value of the default language (recommended)', array(), self::DOMINIO);
        $this->trans('Log', array(), self::DOMINIO);
        $this->trans('Log files emptied.', array(), self::DOMINIO);
        $this->trans('Logs', array(), self::DOMINIO);
        $this->trans('Module activity', array(), self::DOMINIO);
        $this->trans('Multilanguage configuration keys', array(), self::DOMINIO);
        $this->trans('Multistore is on: everything is checked shop by shop.', array(), self::DOMINIO);
        $this->trans('Newest first. The files are in modules/ecom_aftermigration/logs/.', array(), self::DOMINIO);
        $this->trans('No', array(), self::DOMINIO);
        $this->trans('No array has reached pSQL() so far.', array(), self::DOMINIO);
        $this->trans('No failures. There are %count% warning(s) to look at.', array(), self::DOMINIO);
        $this->trans('Nothing recorded.', array(), self::DOMINIO);
        $this->trans('Nothing recorded. Turn on debug mode below.', array(), self::DOMINIO);
        $this->trans('OK', array(), self::DOMINIO);
        $this->trans('Only tables whose name ends in _lang and that have id_lang inside the primary key are touched. Rows are copied with INSERT IGNORE from the language that has the most, so nothing already translated is overwritten. Nothing is ever deleted from here.', array(), self::DOMINIO);
        $this->trans('Overrides are disabled in Advanced parameters > Performance: turn them on or nothing here will protect the shop.', array(), self::DOMINIO);
        $this->trans('Overrides are disabled in this shop: they will not protect anything.', array(), self::DOMINIO);
        $this->trans('Overrides installed (%count% file(s)) and cache cleared.', array(), self::DOMINIO);
        $this->trans('PrestaShop %ps% with PHP %php%.', array(), self::DOMINIO);
        $this->trans('PrestaShop is ignoring every override (Advanced parameters > Performance).', array(), self::DOMINIO);
        $this->trans('Products, categories or CMS pages with no row for one of the languages come out empty in the front office.', array(), self::DOMINIO);
        $this->trans('Protection overrides', array(), self::DOMINIO);
        $this->trans('Record the arrays caught by the override', array(), self::DOMINIO);
        $this->trans('Remove the overrides', array(), self::DOMINIO);
        $this->trans('Removed %count% override file(s) and cleared the cache.', array(), self::DOMINIO);
        $this->trans('Repair', array(), self::DOMINIO);
        $this->trans('Repairs', array(), self::DOMINIO);
        $this->trans('Row limit per language table', array(), self::DOMINIO);
        $this->trans('Rows missing in the language tables', array(), self::DOMINIO);
        $this->trans('Save', array(), self::DOMINIO);
        $this->trans('Settings', array(), self::DOMINIO);
        $this->trans('Settings saved.', array(), self::DOMINIO);
        $this->trans('Show the detail', array(), self::DOMINIO);
        $this->trans('Status', array(), self::DOMINIO);
        $this->trans('Store an empty value', array(), self::DOMINIO);
        $this->trans('Tables with more rows than this are only reported, never repaired automatically.', array(), self::DOMINIO);
        $this->trans('The check could not be run: %error%', array(), self::DOMINIO);
        $this->trans('The class index is regenerated on its own. If the shop keeps behaving as before, empty the cache in Advanced parameters > Performance and check that overrides are not disabled there.', array(), self::DOMINIO);
        $this->trans('The folder %folder% could not be created.', array(), self::DOMINIO);
        $this->trans('The overrides go to override/classes/. Nothing that is not ours is overwritten.', array(), self::DOMINIO);
        $this->trans('The repair failed: %error%', array(), self::DOMINIO);
        $this->trans('There are leftover rows in configuration_lang.', array(), self::DOMINIO);
        $this->trans('There is a newer version of this module', array(), self::DOMINIO);
        $this->trans('This check has to be fixed by hand.', array(), self::DOMINIO);
        $this->trans('This one is not repaired from here: the module that sends the array has to be corrected.', array(), self::DOMINIO);
        $this->trans('Uninstalling does not undo the repairs already applied. The overrides, if installed, stay in place.', array(), self::DOMINIO);
        $this->trans('Unknown check.', array(), self::DOMINIO);
        $this->trans('Warning', array(), self::DOMINIO);
        $this->trans('What the Db override does when a module sends an array where a string is expected.', array(), self::DOMINIO);
        $this->trans('Without them, validating an order ends in a fatal error and the customer cannot finish the purchase.', array(), self::DOMINIO);
        $this->trans('Writes every check and every repair to logs/ecom_aftermigration.log.', array(), self::DOMINIO);
        $this->trans('Writes the offending file and line to logs/escape.log. This is what tells you which module has to be fixed.', array(), self::DOMINIO);
        $this->trans('Yes', array(), self::DOMINIO);
        $this->trans('an older version is installed', array(), self::DOMINIO);
        $this->trans('installed', array(), self::DOMINIO);
        $this->trans('missing languages:', array(), self::DOMINIO);
        $this->trans('not installed', array(), self::DOMINIO);
        $this->trans('there is another override in place, merge it by hand', array(), self::DOMINIO);
    }
    // </cadenas-extractor>

    /**
     * Registra el modulo en modules.gmartos.es y avisa si hay version nueva.
     * Un solo intento por semana, 3 segundos de espera como mucho, y si falla,
     * falla en silencio. Sin Guzzle ni dependencias de Composer.
     *
     * @return bool
     */
    private function comprobarActualizacionGmartos()
    {
        $ultimo = (int) Configuration::get('GMARTOS_LAST_PING_' . $this->name);
        if ((time() - $ultimo) < 604800) {
            return (bool) Configuration::get('GMARTOS_UPDATE_AVAILABLE_' . $this->name);
        }

        $datos = json_encode(array(
            'action' => 'register',
            'module_name' => $this->name,
            'version' => $this->version,
            'url' => Tools::getShopDomainSsl(true),
            'email' => Configuration::get('PS_SHOP_EMAIL'),
        ));

        $cuerpo = $this->peticionJson('https://modules.gmartos.es/api.php', $datos);
        if ($cuerpo === false) {
            return false;
        }

        $json = json_decode($cuerpo, true);
        if (!is_array($json) || empty($json['success'])) {
            return false;
        }

        Configuration::updateValue('GMARTOS_LAST_PING_' . $this->name, time());

        if (!empty($json['update_available'])) {
            Configuration::updateValue('GMARTOS_UPDATE_AVAILABLE_' . $this->name, true);
            Configuration::updateValue('GMARTOS_LATEST_VERSION_' . $this->name, isset($json['latest_version']) ? $json['latest_version'] : '');

            return true;
        }

        Configuration::updateValue('GMARTOS_UPDATE_AVAILABLE_' . $this->name, false);

        return false;
    }

    /**
     * POST de JSON con funciones nativas. Devuelve el cuerpo o false.
     *
     * @param string $url
     * @param string $json
     *
     * @return string|false
     */
    private function peticionJson($url, $json)
    {
        if (function_exists('curl_init')) {
            $ch = \curl_init($url);
            if ($ch === false) {
                return false;
            }
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            \curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $cuerpo = \curl_exec($ch);
            $codigo = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            return ($codigo === 200 && is_string($cuerpo)) ? $cuerpo : false;
        }

        $contexto = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 3,
                'ignore_errors' => true,
            ),
        ));

        $cuerpo = @file_get_contents($url, false, $contexto);

        return is_string($cuerpo) ? $cuerpo : false;
    }
}
