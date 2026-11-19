<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Etc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MviewXmlTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function productSubscriptions(): array
    {
        return [
            'website assignment' => ['catalog_product_website', 'product_id'],
            'parent/child relation' => ['catalog_product_relation', 'parent_id'],
        ];
    }

    #[DataProvider('productSubscriptions')]
    public function test_product_view_subscribes_to_table(string $table, string $entityColumn): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/mview.xml');
        self::assertNotFalse($xml);

        $matches = $xml->xpath(sprintf(
            '//view[@id="typesense_product"]/subscriptions/table[@name="%s"][@entity_column="%s"]',
            $table,
            $entityColumn,
        ));

        self::assertCount(1, $matches);
    }
}
