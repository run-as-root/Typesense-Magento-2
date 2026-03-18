<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Integration\Model\Indexer\Product;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for product indexing pipeline.
 * Requires running Typesense instance and Magento fixtures.
 *
 * @group integration
 */
final class ProductIndexerTest extends TestCase
{
    public function test_full_reindex_creates_collection_and_indexes_products(): void
    {
        self::markTestIncomplete('Requires Magento integration test framework with Typesense service');
    }

    public function test_partial_reindex_updates_changed_products(): void
    {
        self::markTestIncomplete('Requires Magento integration test framework with Typesense service');
    }

    public function test_zero_downtime_reindex_swaps_alias_atomically(): void
    {
        self::markTestIncomplete('Requires Magento integration test framework with Typesense service');
    }
}
