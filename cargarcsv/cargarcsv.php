<?php
/**
 * importer Module for PrestaShop
 *
 * This module allows to import products from CSV files into PrestaShop.
 *
 * @author    Sebastián Luna Polo
 * @version   1.0.1
 */
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
        $this->description = $this->l('Import products from CSV');
 
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
        if (((bool)Tools::isSubmit('Import_catalogue')) == true) {

            $this->importCSV();
        
            $this->_confirmations[] = $this->l('Successfully imported');
        
            // Execute postProcess if exists
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
        $helper->submit_action = 'Import_catalogue';
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
     * CSV columns mapping
     */
    private const CSV_ID              = 0;
    private const CSV_REFERENCE       = 1;
    private const CSV_NAME            = 2;
    private const CSV_PRICE_RAW       = 3;
    private const CSV_PRICE_TAX       = 4;
    private const CSV_STOCK           = 5;
    private const CSV_CATEGORY        = 6;
    private const CSV_SUBCATEGORY     = 7;
    private const CSV_SUMMARY         = 8;
    private const CSV_PORTRAIT        = 9;
 
    /**
     * Parses the CSV file and imports or updates products dynamically.
     * It handles dynamic category association and image downloads.
     * Expected CSV columns: ID, Reference, Name, PriceExcludingTax, PriceIncludingTax, Stock, Category, Summary, Description, CoverURL
     */
    public function importCSV()
    {
        $fileName = $this->local_path . "catalogo.csv";
 
        if (($fileHandler = fopen($fileName, "r")) !== false) {
            // Skip the CSV header row
            fgetcsv($fileHandler);
 
            $idLanguage = (int)Context::getContext()->language->id;
 
            while (($row = fgetcsv($fileHandler, 0, ",")) !== false) {
 
                if (!empty($row[self::CSV_REFERENCE]) && !empty($row[self::CSV_NAME]) && !empty($row[self::CSV_PRICE_RAW])){
 
                    $reference      = $row[self::CSV_REFERENCE];
                    $name           = $row[self::CSV_NAME];
                    $priceRaw       = (float)$row[self::CSV_PRICE_RAW];
                    $stock          = (int)$row[self::CSV_STOCK];
 
                    $parentCategory = $row[self::CSV_CATEGORY];
                    $sonCategories  = empty($row[self::CSV_SUBCATEGORY]) ?  [] : explode('/', $row[self::CSV_SUBCATEGORY]);
 
                    $summary        = $row[self::CSV_SUMMARY];
                    $portrait       = $row[self::CSV_PORTRAIT];
 
                    //Category headache
                    if ($parentCategory !== '') {
 
                        $idParentCategory = $this->obtainOrCreateCategory($parentCategory, 2, $idLanguage );
                        $idCategories = [$idParentCategory];
 
                        if(!empty($sonCategories)){
                            foreach ($sonCategories as $sonCategory){
 
                                $sonCategory = trim($sonCategory);
                                $idSonCategory = $this->obtainOrCreateCategory($sonCategory, $idParentCategory, $idLanguage);
 
                                $idCategories[] = $idSonCategory;
                            }
                        }
 
                    } else {
                        $idCategories = [2];
                    }
    
                    $maybeId = Product::getIdByReference($reference);
                    //Create the product
                    if ($maybeId === false) {
 
                        $product = new Product();
                        $product->reference = $reference;
                        
                        $product->name[$idLanguage]              = $name;
                        $product->link_rewrite[$idLanguage]      = Tools::str2url($name);
                        $product->description_short[$idLanguage] = stripslashes($summary);
                        
                        $product->price = $priceRaw;
                        $product->id_tax_rules_group = 1;
                        $product->id_category_default = $idCategories[0];
                        $product->active = 1;
    
                        $product->add();
    
                    // Update the product
                    } else{
                        $product = new Product((int)$maybeId);
    
                        $product->price = $priceRaw;
                        $product->id_tax_rules_group = 1;
                        
                        // Category
                        $product->id_category_default = $idCategories[0];
    
                        // Multilanguage text fields
                        $product->name[$idLanguage]              = $name;
                        $product->link_rewrite[$idLanguage]      = Tools::str2url($name);
                        $product->description_short[$idLanguage] = stripslashes($summary);
 
                        $product->active = 1;
                        
                        $product->update();
                    }
    
                    // Bind product to the category in the mapping table
                    $product->updateCategories($idCategories);
    
                    // Synchronize stock
                    StockAvailable::setQuantity((int)$product->id, 0, $stock);
    
                    // If URL from portrait provided and product's picture is not provided yet, download the picture
                    $existingImages = Image::getImages($idLanguage, (int)$product->id);
                    if (!empty($portrait) && empty($existingImages)) {
                        $this->importProductsImage($product, $portrait);
                    }
 
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
        *  Downloads the product image from the given URL and sets it as the cover image.
        * If the image cannot be copied, the created Image record is deleted to avoid orphans.
        *
        * @param Product $product  The product to assign the image to
        * @param string  $url      Public URL of the image to download
        */
        private function importProductsImage($product, $url){
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