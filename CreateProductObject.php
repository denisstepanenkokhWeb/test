<?php

namespace Bla\CustomAlgoliaSearch\Observer;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class CreateProductObject implements \Magento\Framework\Event\ObserverInterface
{
    const XML_B2C_SITE_PART = "b2c";
    const XML_B2B_SITE_PART = "b2b";
    /**
     * @var \Bla\BrandLogo\Block\Product\Logo $brandLogoBlock
     */
    private $brandLogoBlock;

    /**
     * @var \Magento\Backend\Helper\Data $wishlistHelper
     */
    private $wishlistHelper;

    /**
     * @var \Bla\DisplayTax\Helper\TaxDisplayHelper $taxHelper
     */
    private $taxHelper;

    /**
     * @var \Magento\Framework\Escaper $escaper
     */
    private $escaper;
    /**
     * @var \Magento\Backend\Helper\Data $backendHelper
     */
    private $backendHelper;

    private $storeManager;

    /**
     * CreateProductObject constructor.
     * @param \Bla\BrandLogo\Block\Product\Logo $brandLogoBlock
     * @param \Magento\Wishlist\Helper\Data $wishlistHelper
     * @param \Bla\DisplayTax\Helper\TaxDisplayHelper $taxHelper
     * @param \Magento\Framework\Escaper $escaper
     * @param \Magento\Backend\Helper\Data $backendHelper
     */
    public function __construct(
        \Bla\BrandLogo\Block\Product\Logo $brandLogoBlock,
        \Magento\Wishlist\Helper\Data $wishlistHelper,
        \Bla\DisplayTax\Helper\TaxDisplayHelper $taxHelper,
        \Magento\Framework\Escaper $escaper,
        \Magento\Backend\Helper\Data $backendHelper,
        StoreManagerInterface $storeManager
    ) {
        $this->brandLogoBlock = $brandLogoBlock;
        $this->wishlistHelper = $wishlistHelper;
        $this->taxHelper = $taxHelper;
        $this->escaper = $escaper;
        $this->backendHelper = $backendHelper;
        $this->storeManager = $storeManager;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getData()['productObject'];
        /** @var \Magento\Framework\DataObject; $algoliaProductData */
        $algoliaProductData = $observer->getData()['custom_data'];
        $algoliaProductData->setData('logo_url', $this->brandLogoBlock->getImagePathByBrandName($algoliaProductData->getData('at_brand')));
        $algoliaProductData->setData('wish_params', $this->generateWishlistUrlByProduct($product));
        $algoliaProductData->setData('tax_info', $this->getTaxInfo($product));
        $websiteCode = $this->getWebsiteCode($product->getStoreId());
        $algoliaProductData->setData('is_product_saleable', $this->isProductSaleable($product,$websiteCode));
        $algoliaProductData->setData('is_product_visible', $this->isProductVisible($product,$websiteCode));
    }

    /**
     * @param $product
     * @return false|string
     */
    public function generateWishlistUrlByProduct($product)
    {
        $urlJson = $this->wishlistHelper->getAddParams($product);
        $urlObj = json_decode($urlJson);
        $urlObj->action = $this->fixUrlAction($urlObj->action);
        return json_encode($urlObj);
    }

    /**
     * @param $action
     * @return string|string[]
     */
    public function fixUrlAction($action)
    {
        $homeUrl = $this->backendHelper->getHomePageUrl();
        preg_match('/[a-zA-Z0-9-.]+\/(.*)\//i', $homeUrl, $matches);
        if (count($matches) > 1) {
            $backendLink = $matches[1];
            $searchData = explode('/', $backendLink);
            $searchData = array_map(function ($value) {
                return '/' . $value;
            }, $searchData);
            $action = str_replace($searchData, '', $action);
        }

        return $action;
    }

    /**
     * @param $product
     * @return array|string
     */
    protected function getTaxInfo($product)
    {
        $taxTranslationKey = 'net, excl. %shipping';
        $tax = [];

        try {
            $isTaxRate = false;
            $changeTaxKey = '';
            if ($this->taxHelper->showAlwaysTaxAmount()) {
                $taxTranslationKey = 'plus. %tax% VAT and %shipping';
                $isTaxRate = true;
                $changeTaxKey = 'always_tax';
            } elseif ($this->taxHelper->isCatalogPriceDisplayedIncludingTax()) {
                $taxTranslationKey = 'incl. %tax% VAT plus %shipping';
                $isTaxRate = true;
                $changeTaxKey = 'include_tax';
            }
            $tax['tax_key'] = $taxTranslationKey;
            if ($changeTaxKey) {
                $tax['change_key'] = $changeTaxKey;
            }
            if ($isTaxRate) {
                $tax['tax_rate'] = $this->taxHelper->getProductTaxRate($product);
            }
            $tax['tax_url'] = $this->taxHelper->getShippingCostPageUrl();

            return $tax;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return $this->escaper->escapeHtml('tax calculation error');
        }
    }


    /**
     * Get website code
     *
     * @return string|null
     */
    public function getWebsiteCode($storeId)
    {
        try {
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            $websiteCode = $this->storeManager->getWebsite($websiteId)->getCode();
        } catch (LocalizedException $localizedException) {
            $websiteCode = 0;
        }
        return $websiteCode;
    }

    /**
     * @param $product
     * @param $websiteCode
     * @return bool
     */
    protected function isProductSaleable($product , $websiteCode){
        $result = false;
        if(strpos($websiteCode, self::XML_B2C_SITE_PART) !== false && (bool)$product->getData("b2c_status") !== false){
            $result = true;
        }elseif (strpos($websiteCode, self::XML_B2B_SITE_PART) !== false && (bool)$product->getData("b2b_status") !== false){
            $result = true;
        }
        return $result;
    }

    /**
     * @param $product
     * @param $websiteCode
     * @return bool
     */
    protected function isProductVisible($product , $websiteCode){

        $result = false;
        if(strpos($websiteCode, self::XML_B2C_SITE_PART) !== false && $product->getData("b2c_visibility") !== "0"){
            $result = true;
        }elseif (strpos($websiteCode, self::XML_B2B_SITE_PART) !== false && $product->getData("b2b_visibility") !== "0"){
            $result = true;
        }
        return $result;
    }
}
