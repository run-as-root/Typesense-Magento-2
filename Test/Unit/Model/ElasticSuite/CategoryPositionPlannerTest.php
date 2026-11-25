<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\ElasticSuite\CategoryPositionPlanner;

final class CategoryPositionPlannerTest extends TestCase
{
    private CategoryPositionPlanner $sut;

    protected function setUp(): void
    {
        $this->sut = new CategoryPositionPlanner();
    }

    public function test_assigned_products_take_their_elasticsuite_position(): void
    {
        $plan = $this->sut->plan([10 => 2, 11 => 1], [10 => 0, 11 => 1], []);

        self::assertSame([10 => 2], $plan['positions']);
        self::assertSame([10], $plan['updated']);
        self::assertSame([11], $plan['unchanged']);
        self::assertSame([], $plan['created']);
    }

    public function test_unpositioned_products_move_behind_the_positioned_ones_in_their_magento_order(): void
    {
        $plan = $this->sut->plan([10 => 1, 11 => 2], [10 => 5, 11 => 6, 20 => 3, 21 => 0, 22 => 3], []);

        // 21 (0), then 20 and 22 (3, by product id), all after the highest ElasticSuite position.
        self::assertSame([10 => 1, 11 => 2, 21 => 3, 20 => 4, 22 => 5], $plan['positions']);
        self::assertSame([21, 20, 22], $plan['moved']);
    }

    public function test_unpositioned_products_already_behind_the_positioned_ones_are_left_alone(): void
    {
        $plan = $this->sut->plan([10 => 1], [10 => 1, 20 => 7, 21 => 4], []);

        self::assertSame([], $plan['positions']);
        self::assertSame([], $plan['moved']);
        self::assertSame([10], $plan['unchanged']);
    }

    public function test_anchor_inherited_product_is_assigned_to_hold_its_position(): void
    {
        $plan = $this->sut->plan([30 => 1], [], [30, 31]);

        self::assertSame([30 => 1], $plan['positions']);
        self::assertSame([30], $plan['created']);
    }

    public function test_product_not_listed_in_the_category_is_skipped(): void
    {
        $plan = $this->sut->plan([40 => 1, 10 => 2], [10 => 2], []);

        self::assertSame([], $plan['positions']);
        self::assertSame([['product' => 40, 'reason' => 'not listed in the category (neither assigned nor inherited by an anchor)']], $plan['skipped']);
        self::assertSame([10], $plan['unchanged']);
    }

    public function test_rows_without_a_position_are_skipped_and_change_nothing(): void
    {
        $plan = $this->sut->plan([10 => null, 11 => 0], [10 => 0, 11 => 0, 12 => 0], []);

        self::assertSame([], $plan['positions']);
        self::assertSame(
            [
                ['product' => 10, 'reason' => 'no position (ElasticSuite sorted it among the unpositioned products)'],
                ['product' => 11, 'reason' => 'no position (ElasticSuite sorted it among the unpositioned products)'],
            ],
            $plan['skipped'],
        );
    }

    public function test_replanning_an_imported_category_changes_nothing(): void
    {
        $first = $this->sut->plan([10 => 1, 30 => 2], [10 => 4, 20 => 0], [30]);
        $direct = [10 => 4, 20 => 0];
        foreach ($first['positions'] as $productId => $position) {
            $direct[$productId] = $position;
        }

        $second = $this->sut->plan([10 => 1, 30 => 2], $direct, [30]);

        self::assertSame([], $second['positions']);
        self::assertSame([10, 30], $second['unchanged']);
    }
}
