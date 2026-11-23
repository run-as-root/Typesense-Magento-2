<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * "Set Product as New From/To" as in Magento's new products widget. Evaluated at index time, so the
 * flag only follows the date window when products are reindexed (e.g. by the scheduled full reindex).
 */
class NewProductResolver implements NewProductResolverInterface
{
    public function __construct(
        private readonly TimezoneInterface $timezone,
    ) {
    }

    public function isNew(Product $product, int $storeId): bool
    {
        $from = $this->toDay($product->getData('news_from_date'));
        $to = $this->toDay($product->getData('news_to_date'));

        if ($from === '' && $to === '') {
            return false;
        }

        $today = $this->timezone->scopeDate($storeId)->format('Y-m-d');

        return ($from === '' || $from <= $today) && ($to === '' || $to >= $today);
    }

    private function toDay(mixed $date): string
    {
        return substr(trim((string) $date), 0, 10);
    }
}
