<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\SearchableAttributeOptions;
use RunAsRoot\TypeSense\Model\Search\SearchableAttributesProvider;

final class SearchableAttributeOptionsTest extends TestCase
{
    public function test_maps_provider_options_to_value_label_pairs(): void
    {
        $provider = $this->createMock(SearchableAttributesProvider::class);
        $provider->method('getOptions')->willReturn(['name' => 'Name', 'color' => 'Color']);
        $sut = new SearchableAttributeOptions($provider);

        self::assertSame(
            [['value' => 'name', 'label' => 'Name'], ['value' => 'color', 'label' => 'Color']],
            $sut->toOptionArray(),
        );
    }
}
