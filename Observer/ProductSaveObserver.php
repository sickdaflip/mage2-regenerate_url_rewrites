<?php
/**
 * ProductSaveObserver.php
 *
 * @package OlegKoval_RegenerateUrlRewrites
 * @author Oleg Koval <olegkoval.ca@gmail.com>
 * @copyright 2017-2067 Oleg Koval
 * @license OSL-3.0, AFL-3.0
 */

namespace OlegKoval\RegenerateUrlRewrites\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OlegKoval\RegenerateUrlRewrites\Helper\Regenerate as RegenerateHelper;

class ProductSaveObserver implements ObserverInterface
{
    /**
     * @var RegenerateHelper
     */
    private $helper;

    /**
     * @param RegenerateHelper $helper
     */
    public function __construct(RegenerateHelper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * Transliterate German umlauts in product name and generate url_key if empty
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();

        if (!$product) {
            return;
        }

        $name = (string)$product->getName();
        if ($name === '') {
            return;
        }

        $urlKey = (string)$product->getUrlKey();
        if ($urlKey === '') {
            $transliteratedName = $this->helper->transliterateGermanCharacters($name);
            $product->setUrlKey($this->formatUrlKey($transliteratedName));
        } else {
            $transliteratedKey = $this->helper->transliterateGermanCharacters($urlKey);
            if ($transliteratedKey !== $urlKey) {
                $product->setUrlKey($this->formatUrlKey($transliteratedKey));
            }
        }
    }

    /**
     * Convert string to a valid URL key segment
     *
     * @param string $value
     * @return string
     */
    private function formatUrlKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-');
    }
}
