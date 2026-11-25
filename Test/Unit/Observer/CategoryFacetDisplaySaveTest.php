<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Listing\CategoryFacetDisplay;
use RunAsRoot\TypeSense\Observer\CategoryFacetDisplaySave;

final class CategoryFacetDisplaySaveTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $params = [];
    private CategoryFacetDisplay&MockObject $facetDisplay;
    private CategoryFacetDisplaySave $sut;

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $key): mixed => $this->params[$key] ?? null);
        $this->facetDisplay = $this->createMock(CategoryFacetDisplay::class);
        $this->sut = new CategoryFacetDisplaySave($request, $this->facetDisplay);
    }

    public function test_posted_rows_replace_the_category_settings(): void
    {
        $this->params = [
            'typesense_facet_display_submitted' => '1',
            'entity_id' => '139',
            'typesense_facet_display' => [
                ['attribute_code' => 'gift_for_woman', 'display_mode' => '1'],
                ['attribute_code' => 'taste', 'display_mode' => '2'],
                ['attribute_code' => '', 'display_mode' => '1'],
                ['attribute_code' => 'color', 'display_mode' => '9'],
                ['attribute_code' => 'taste', 'display_mode' => '1'],
            ],
        ];

        $this->facetDisplay->expects(self::once())->method('replace')
            ->with(139, ['gift_for_woman' => 1, 'taste' => 1]);

        $this->sut->execute($this->observer(139));
    }

    public function test_removing_every_row_clears_the_settings(): void
    {
        $this->params = ['typesense_facet_display_submitted' => '1', 'entity_id' => '139'];

        $this->facetDisplay->expects(self::once())->method('replace')->with(139, []);

        $this->sut->execute($this->observer(139));
    }

    public function test_a_new_category_gets_the_rows_under_its_new_id(): void
    {
        $this->params = [
            'typesense_facet_display_submitted' => '1',
            'typesense_facet_display' => [['attribute_code' => 'price', 'display_mode' => '1']],
        ];

        $this->facetDisplay->expects(self::once())->method('replace')->with(300, ['price' => 1]);

        $this->sut->execute($this->observer(300, true));
        $this->sut->execute($this->observer(12));
    }

    public function test_saves_without_the_form_or_of_another_category_change_nothing(): void
    {
        $this->facetDisplay->expects(self::never())->method('replace');

        $this->params = ['entity_id' => '139'];
        $this->sut->execute($this->observer(139));

        $this->params = ['typesense_facet_display_submitted' => '1', 'entity_id' => '139'];
        $this->sut->execute($this->observer(12));
    }

    private function observer(int $categoryId, bool $isNew = false): Observer
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($categoryId);
        $category->method('isObjectNew')->willReturn($isNew);

        return new Observer(['event' => new Event(['category' => $category])]);
    }
}
