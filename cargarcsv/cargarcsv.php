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
     * Parses the CSV file and imports or updates products dynamically.
     * It handles dynamic category association and image downloads.
     * Expected CSV columns: ID, Reference, Name, PriceExcludingTax, PriceIncludingTax, Stock, Category, Summary, Description, CoverURL
     */
    public function importarCatalogo()
    {
        $fileName = $this->local_path . "catalogo.csv";
 
        if (($fileHandler = fopen($fileName, "r")) !== false) {
 
            // Skip the CSV header row
            fgetcsv($fileHandler);
 
            $idLanguage = (int)Context::getContext()->language->id;
 
            // Default Parent Category ID 
            $idCategory = $this->obtainOrCreateCategory("Videojuegos", 2, $idLanguage);
 
            while (($row = fgetcsv($fileHandler, 0, ",")) !== false) {
                
                // CSV Mapping: ID[0], Referencia[1], Nombre[2], PrecioSinIVA[3], PrecioConIVA[4],
                //             Stock[5], Categoria[6], Resumen[7], Portada[8]
 
                if (isset($row[1], $row[2], $row[3])){
                    $reference = $row[1];
                    $name      = $row[2];
                    $priceRaw     = (float)$row[3];
                    //21% of tax by default
                    $priceWithTax = isset($row[4]) ? $row[4] : $priceRaw*1.21; 
                    $stock     = (int)$row[5];
                    $category  = $row[6];
                    $summary   = $row[7];
                    $portrait  = $row[9];
                }
 
                //Category headache
                if ($category !== '') {
                    $idCategory = $this->obtainOrCreateCategory($category, 2, $idLanguage);
                } else {
                    $idCategory = 2;
                }
 
                $maybeId = Product::getIdByReference($reference);
 
                //Create the product
                if ($maybeId === false) {

                    $product = new Product();
                    $product->reference = $reference;
                    
                    $product->name[$idLanguage]              = $name;
                    $product->link_rewrite[$idLanguage]      = Tools::str2url($name);
                    $product->description_short[$idLanguage] = stripslashes($summary);
                    
                    $product->price = $priceWithTax;
                    $product->id_category_default = $idCategory;
                    $product->active = 1;
 
                    $product->add();
 
                // Update the product
                } else {
                    $product = new Product($maybeId);
 
                    $product->price = $priceWithTax;
                    
                    // Category
                    $product->id_category_default = $idCategory;
 
                    // Multilanguage text fields
                    $product->name[$idLanguage]              = $name;
                    $product->link_rewrite[$idLanguage]      = Tools::str2url($name);
                    $product->description_short[$idLanguage] = stripslashes($summary);

 
                    $product->active = 1; 
                    
                    $product->update();
                }
 
                // Bind product to the category in the mapping table
                $product->updateCategories(array($idCategory));
 
                // Synchronize stock
                StockAvailable::setQuantity((int)$product->id, 0, $stock);
 
                // If URL from portrait provided and product's picture is not provided yet, download the picture
                $existingImages = Image::getImages($idLanguage, (int)$product->id);
                if (!empty($portrait) && empty($existingImages)) {
                    $this->importProductsImage($product, $portrait);
                }
            }
 
            fclose($fileHandler);
        }
    }
 
    /**
     * Returns a category ID using its name. 
     * Creates the category under the specified parent if it does not exist.
     * It's planned to work with Videogames category and subcategorys of it.
     *
     * @param string $name Name of the category
     * @param int $idParent Parent category ID
     * @param int $idLanguage Language ID
     * @return int $finalId id of the category created or searched
     */
    private function obtainOrCreateCategory($name, $idParent, $idLanguage)
    {
        //FIXME: Add subcategories and work solve the issue to asign them
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
     * @param $product given product of the csv that loads an  
     */
    private function importProductsImage($product, $url){
        //TODO Fix what happens when it receives an empty Image from products 
        //with only the necessary attributtes
        $image = new Image();
        $image->id_product = (int)$product->id;
        $image->position = Image::getHighestPosition($product->id) + 1;
        $image->cover = true;
 
        if ($image->add()) {
            if (!ImageManager::copyImg($product->id, $image->id, $url, 'products', false)) {
                $image->delete();
                }
            }
        }
}
