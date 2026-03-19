<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

interface ReviewResolverInterface
{
    public function getRatingSummary(int $productId, int $storeId): int;

    public function getReviewCount(int $productId, int $storeId): int;
}
