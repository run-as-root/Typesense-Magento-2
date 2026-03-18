<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Integration\Console\Command;

use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class ReindexCommandTest extends TestCase
{
    public function test_reindex_command_creates_collections(): void
    {
        self::markTestIncomplete('Requires Magento integration test framework with Typesense service');
    }

    public function test_collection_list_command_shows_created_collections(): void
    {
        self::markTestIncomplete('Requires Magento integration test framework with Typesense service');
    }
}
