<?php
 
if (!defined('_PS_VERSION_')) {
    exit;
}

class CargarCSV extends Module
{
    protected $config_form = false;
 
    public function __construct()
    {
        $this->name = 'cargarcsv';
        $this->tab = 'quick_bulk_update';
        $this->version = '1.0.1';
        $this->author = 'Sebastián Luna Polo';
        $this->need_instance = 0;
 
        $this->bootstrap = true;
 
        parent::__construct();
 
        $this->displayName = $this->l('CargarCSV');
        $this->description = $this->l('Importa CSV a productos de prestashop');
 
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
 
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => '9.4'];
    }
 
    public function install()
    {
        Configuration::updateValue('CARGARCSV_LIVE_MODE', false);
 
        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('displayBackOfficeHeader');
    }
 
    public function uninstall()
    {
        Configuration::deleteByName('CARGARCSV_LIVE_MODE');
 
        return parent::uninstall();
    }
 
    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitCargarCSVModule')) == true) {

            $this->importarCatalogo();
        
            $this->_confirmations[] = $this->l('Successfully imported');
        
            // Ejecuta los procesos por defecto del formulario si los hubiera
            $this->postProcess();
        }
 
        $this->context->smarty->assign('module_dir', $this->_path);
 
        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
 
        return $output.$this->renderForm();
    }
 
    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();
 
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
 
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCargarCSVModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
 
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];
 
        return $helper->generateForm([$this->getConfigForm()]);
    }
 
    /**
     * Creates the structure of the module configuration form.
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('CargarCSV Settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Live mode'),
                        'name'    => 'CARGARCSV_LIVE_MODE',
                        'is_bool' => true,
                        'desc'    => $this->l('Enable this module on the live store'),
                        'values'  => [
                            [
                                'id'    => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id'    => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type'  => 'free',
                        'name'  => 'CARGARCSV_INFO',
                        'label' => $this->l('Information'),
                        'desc'  => $this->l(
                            'Clicking "Import catalogue" will read the catalogo.csv file located in the '
                            . 'module root folder. Expected columns: '
                            . 'ID, Reference, Name, PriceExcludingTax, PriceIncludingTax, Stock, Category, Summary, Cover.'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Import catalogue'),
                    'icon'  => 'process-icon-upload',
                ],
            ],
        ];
    }
 
    /**
     * Returns the current configuration values for the form inputs.
     */
    protected function getConfigFormValues()
    {
        return [
            'CARGARCSV_LIVE_MODE' => Configuration::get('CARGARCSV_LIVE_MODE', false),
            'CARGARCSV_INFO'      => '',
        ];
    }
 
    /**
     * Persists the configuration form values.
     */
    protected function postProcess()
    {
        Configuration::updateValue('CARGARCSV_LIVE_MODE', (bool)Tools::getValue('CARGARCSV_LIVE_MODE'));
        return $this->displayConfirmation($this->l('Updated Successfully'));
    }
 
    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }
 
    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }
 
    /**
     * Parses the CSV file and imports or updates products dynamically.
     * It handles dynamic category association and image downloads.
     */
    public function importarCatalogo()
    {
        $fileName = $this->local_path . "catalogo.csv";
 
        if (($fileHandler = fopen($fileName, "r")) !== FALSE) {
 
            // Skip the CSV header row
            fgetcsv($fileHandler);
 
            $idLanguage = (int)Context::getContext()->language->id;
 
            // Default Parent Category ID 
            $idCategory = $this->obtainOrCreateCategory("Videojuegos", 2, $idLanguage);
 
            while (($row = fgetcsv($fileHandler, 4096, ",")) !== FALSE) {
                
                // CSV Mapping: ID[0], Referencia[1], Nombre[2], PrecioSinIVA[3], PrecioConIVA[4],
                //             Stock[5], Categoria[6], Resumen[7], Descripcion[8], Portada[9]
                $referenceCSV    = isset($row[1]) ? trim($row[1]) : '';
                $nameCSV         = isset($row[2]) ? trim($row[2]) : '';
                $priceRaw        = isset($row[3]) ? trim($row[3]) : ''; // Price excl. tax (stored value in DB)
                $priceWithTaxRaw = isset($row[4]) ? trim($row[4]) : ''; // Price incl. tax (informational)
 
                if ($referenceCSV === '' || $nameCSV === '' || $priceRaw === '') {
                    continue;
                }
 
                // PrestaShop stores prices excl. tax internally; the tax rule is applied at display time
                $priceCSV     = (float)$priceRaw;
                $stockCSV     = isset($row[5]) && trim($row[5]) !== '' ? (int)$row[5] : 0;
                $categoryCSV  = isset($row[6]) ? trim($row[6]) : '';
                $summaryCSV   = isset($row[7]) ? trim($row[7]) : '';
                $descriptionCSV = isset($row[8]) ? trim($row[8]) : '';
                $portraitCSV  = isset($row[9]) ? trim($row[9]) : '';
 
                if ($categoryCSV !== '') {
                    $idCategory = $this->obtainOrCreateCategory($categoryCSV, 2, $idLanguage);
                } else {
                    $idCategory = 2;
                }
 
                if (empty($referenceCSV)) {
                    continue;
                }
 
                $idProduct = (int)Product::getIdByReference($referenceCSV);
 
                // Change the product
                if ($idProduct > 0) {
                    $product = new Product($idProduct);
    
                    // Update price, category, summary, description and cover
 
                    // Price excl. tax (PrestaShop applies the tax group rule when displaying it)
                    $product->price = $priceCSV;
                    
                    // Category
                    $product->id_category_default = $idCategory;
 
                    // Multilanguage text fields
                    $product->name[$idLanguage]              = $nameCSV;
                    $product->link_rewrite[$idLanguage]      = Tools::str2url($nameCSV);
                    $product->description_short[$idLanguage] = stripslashes($summaryCSV);
                    $product->description[$idLanguage]       = stripslashes($descriptionCSV);
 
                    $product->active = 1; 
                    
                    $product->update();
 
                //Create the product
                } else {
                    $product = new Product();
                    $product->reference = $referenceCSV;
                    
                    $product->name[$idLanguage]              = $nameCSV;
                    $product->link_rewrite[$idLanguage]      = Tools::str2url($nameCSV);
                    $product->description_short[$idLanguage] = stripslashes($summaryCSV);
                    $product->description[$idLanguage]       = stripslashes($descriptionCSV);
                    
                    $product->price = $priceCSV;
                    $product->id_category_default = $idCategory;
                    $product->active = 1;
 
                    $product->add();
                }
 
                // Bind product to the category in the mapping table
                $product->updateCategories(array($idCategory));
 
                // Synchronize stock
                StockAvailable::setQuantity((int)$product->id, 0, $stockCSV);
 
                // If URL from portrait provided and product's picture is not provided yet, download the photo
                $existingImages = Image::getImages($idLanguage, (int)$product->id);
                if (!empty($portraitCSV) && empty($existingImages)) {
                    $this->importrProductsImage($product, $portraitCSV);
                }
            }
 
            fclose($fileHandler);
        }
    }
 
    /**
     * Returns a category ID using its name. 
     * Creates the category under the specified parent if it does not exist.
     *
     * @param string $name Name of the category
     * @param int $idParent Parent category ID
     * @param int $idLanguage Language ID
     * @return int Category ID
     */
    private function obtainOrCreateCategory($name, $idParent, $idLanguage)
    {
        $ids = Category::searchByNameAndParentCategoryId($idLanguage, $name, $idParent);
        $finalId = 0;
 
        if (!empty($ids)) {
            $finalId = (int)$ids['id_category'];
        } else {
            $category = new Category();
 
            $category->name[$idLanguage] = $name;
            $category->link_rewrite[$idLanguage] = Tools::str2url($name);
            $category->id_parent = (int)$idParent;
            $category->active = 1;
            $category->add();
 
            $finalId = (int)$category->id;
        }
 
        return $finalId;
    }
 
    /**
     * Downloads the product image from the given URL (Steam or any other source)
     * and sets it as the cover image of the product in PrestaShop.
     */
    private function importrProductsImage($product, $url)
    {
        $image = new Image();
        $image->id_product = (int)$product->id;
        $image->position = Image::getHighestPosition($product->id) + 1;
        $image->cover = true;
 
        if ($image->add()) 
            {
            if (!ImageManager::copyImg($product->id, $image->id, $url, 'products', false)) 
                {
                $image->delete();
            }
        }
    }
 
    
}