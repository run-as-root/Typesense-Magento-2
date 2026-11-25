<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\ElasticSuite;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Model\ElasticSuite\ThesaurusMapper;

final class ThesaurusMapperTest extends TestCase
{
    private ThesaurusMapper $sut;

    protected function setUp(): void
    {
        $this->sut = new ThesaurusMapper();
    }

    public function test_synonym_group_of_one_term_id_becomes_a_multi_way_synonym(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(10, 'Grappa', 'synonym')],
            [10 => [0]],
            [
                ['thesaurus_id' => 10, 'term_id' => 1, 'term' => 'grappa'],
                ['thesaurus_id' => 10, 'term_id' => 1, 'term' => 'trester brand'],
            ],
            [],
        );

        self::assertSame([[
            'typesense_id' => 'es-thesaurus-10-1',
            'type' => SynonymInterface::TYPE_MULTI,
            'root' => null,
            'synonyms' => ['grappa', 'trester brand'],
            'store_ids' => [0],
            'label' => 'thesaurus #10 "Grappa" group 1',
        ]], $result['definitions']);
        self::assertSame([], $result['skipped']);
    }

    public function test_each_term_id_of_a_synonym_thesaurus_is_its_own_group(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(4, 'Mirabelle', 'synonym')],
            [4 => [0]],
            [
                ['thesaurus_id' => 4, 'term_id' => 1, 'term' => 'marille'],
                ['thesaurus_id' => 4, 'term_id' => 2, 'term' => 'mirabelle'],
            ],
            [],
        );

        self::assertSame([], $result['definitions']);
        self::assertSame(
            [
                [
                    'subject' => 'thesaurus #4 "Mirabelle" group 1 (marille)',
                    'reason' => 'a synonym group with a single term has no effect (in ElasticSuite neither)',
                ],
                [
                    'subject' => 'thesaurus #4 "Mirabelle" group 2 (mirabelle)',
                    'reason' => 'a synonym group with a single term has no effect (in ElasticSuite neither)',
                ],
            ],
            $result['skipped'],
        );
    }

    public function test_expansion_maps_reference_term_to_its_expanded_terms_as_one_way_synonym(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(3, 'Zwetschke', 'expansion')],
            [3 => [3]],
            [
                ['thesaurus_id' => 3, 'term_id' => 1, 'term' => 'pflümli'],
                ['thesaurus_id' => 3, 'term_id' => 1, 'term' => 'zwetschge'],
            ],
            [['thesaurus_id' => 3, 'term_id' => 1, 'term' => 'zwetschke']],
        );

        self::assertSame([[
            'typesense_id' => 'es-thesaurus-3-1',
            'type' => SynonymInterface::TYPE_ONE_WAY,
            'root' => 'zwetschke',
            'synonyms' => ['pflümli', 'zwetschge'],
            'store_ids' => [3],
            'label' => 'thesaurus #3 "Zwetschke" group 1',
        ]], $result['definitions']);
    }

    public function test_expansion_groups_with_the_same_reference_stay_separate_definitions(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(12, 'Strudel', 'expansion')],
            [12 => [0]],
            [
                ['thesaurus_id' => 12, 'term_id' => 1, 'term' => 'apfelstrudel'],
                ['thesaurus_id' => 12, 'term_id' => 2, 'term' => 'marillenstrudel'],
            ],
            [
                ['thesaurus_id' => 12, 'term_id' => 1, 'term' => 'strudel'],
                ['thesaurus_id' => 12, 'term_id' => 2, 'term' => 'strudel'],
            ],
        );

        self::assertSame(['es-thesaurus-12-1', 'es-thesaurus-12-2'], array_column($result['definitions'], 'typesense_id'));
        self::assertSame([['apfelstrudel'], ['marillenstrudel']], array_column($result['definitions'], 'synonyms'));
    }

    public function test_expansion_without_reference_term_is_skipped(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(8, 'Aprikose', 'expansion')],
            [8 => [0]],
            [['thesaurus_id' => 8, 'term_id' => 1, 'term' => 'marille']],
            [],
        );

        self::assertSame([], $result['definitions']);
        self::assertSame('an expansion without a reference term', $result['skipped'][0]['reason']);
    }

    public function test_reference_term_is_not_repeated_among_its_expansions(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(6, 'Subirer', 'expansion')],
            [6 => [0]],
            [
                ['thesaurus_id' => 6, 'term_id' => 1, 'term' => 'subirer'],
                ['thesaurus_id' => 6, 'term_id' => 1, 'term' => ' saubirne '],
            ],
            [['thesaurus_id' => 6, 'term_id' => 1, 'term' => 'subirer']],
        );

        self::assertSame(['saubirne'], $result['definitions'][0]['synonyms']);
    }

    public function test_inactive_thesaurus_is_skipped(): void
    {
        $thesaurus = $this->thesaurus(20, 'Kaiserpunsch', 'expansion');
        $thesaurus['is_active'] = false;

        $result = $this->sut->map(
            [$thesaurus],
            [20 => [0]],
            [['thesaurus_id' => 20, 'term_id' => 1, 'term' => 'punsch']],
            [['thesaurus_id' => 20, 'term_id' => 1, 'term' => 'kaiserpunsch']],
        );

        self::assertSame([], $result['definitions']);
        self::assertSame(
            [['subject' => 'thesaurus #20 "Kaiserpunsch"', 'reason' => 'inactive']],
            $result['skipped'],
        );
    }

    public function test_thesaurus_without_store_assignment_is_skipped(): void
    {
        $result = $this->sut->map(
            [$this->thesaurus(9, 'Grappa', 'expansion')],
            [],
            [['thesaurus_id' => 9, 'term_id' => 1, 'term' => 'trester brand']],
            [['thesaurus_id' => 9, 'term_id' => 1, 'term' => 'grappa']],
        );

        self::assertSame('not assigned to any store view', $result['skipped'][0]['reason']);
    }

    public function test_unknown_thesaurus_type_is_skipped(): void
    {
        $result = $this->sut->map([$this->thesaurus(1, 'Odd', 'stemming')], [1 => [0]], [], []);

        self::assertSame('unknown thesaurus type "stemming"', $result['skipped'][0]['reason']);
    }

    /**
     * @return array{thesaurus_id: int, name: string, type: string, is_active: bool}
     */
    private function thesaurus(int $id, string $name, string $type): array
    {
        return ['thesaurus_id' => $id, 'name' => $name, 'type' => $type, 'is_active' => true];
    }
}
