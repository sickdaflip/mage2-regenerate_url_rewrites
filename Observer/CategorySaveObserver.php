<?php
/**
 * CategorySaveObserver.php
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

class CategorySaveObserver implements ObserverInterface
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
     * Transliterate German umlauts in category name and generate url_key if empty
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Category $category */
        $category = $observer->getEvent()->getCategory();

        if (!$category) {
            return;
        }

        $name = (string)$category->getName();
        if ($name === '') {
            return;
        }

        $transliteratedName = $this->helper->transliterateGermanCharacters($name);

        $urlKey = (string)$category->getUrlKey();
        if ($urlKey === '') {
            $urlKey = $this->formatUrlKey($transliteratedName);
            $category->setUrlKey($urlKey);
        } else {
            $transliteratedKey = $this->helper->transliterateGermanCharacters($urlKey);
            if ($transliteratedKey !== $urlKey) {
                $category->setUrlKey($this->formatUrlKey($transliteratedKey));
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
