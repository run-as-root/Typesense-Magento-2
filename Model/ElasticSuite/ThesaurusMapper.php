<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

use RunAsRoot\TypeSense\Api\Data\SynonymInterface;

/**
 * Mirrors ElasticSuite's thesaurus indexer (Smile\ElasticsuiteThesaurus\Model\ResourceModel\Indexer\Thesaurus):
 * every term_id of a thesaurus is its own group. A synonym group is a comma list of equivalent terms; an
 * expansion group rewrites its reference term (smile_elasticsuite_thesaurus_reference_terms, same term_id)
 * into the expanded terms, so it becomes a one-way synonym rooted at the reference term.
 */
class ThesaurusMapper
{
    public const string TYPE_SYNONYM = 'synonym';
    public const string TYPE_EXPANSION = 'expansion';

    /**
     * @param list<array{thesaurus_id: int, name: string, type: string, is_active: bool}> $thesauri
     * @param array<int, list<int>> $storeIds store ids by thesaurus id, 0 meaning all store views
     * @param list<array{thesaurus_id: int, term_id: int, term: string}> $expandedTerms
     * @param list<array{thesaurus_id: int, term_id: int, term: string}> $referenceTerms
     * @return array{
     *     definitions: list<array{
     *         typesense_id: string,
     *         type: string,
     *         root: string|null,
     *         synonyms: list<string>,
     *         store_ids: list<int>,
     *         label: string,
     *     }>,
     *     skipped: list<array{subject: string, reason: string}>,
     * }
     */
    public function map(array $thesauri, array $storeIds, array $expandedTerms, array $referenceTerms): array
    {
        $groups = $this->groupTerms($expandedTerms);
        $references = [];
        foreach ($referenceTerms as $row) {
            $references[$row['thesaurus_id']][$row['term_id']] = trim($row['term']);
        }

        $definitions = [];
        $skipped = [];
        foreach ($thesauri as $thesaurus) {
            $id = $thesaurus['thesaurus_id'];
            $subject = sprintf('thesaurus #%d "%s"', $id, $thesaurus['name']);

            if (!$thesaurus['is_active']) {
                $skipped[] = ['subject' => $subject, 'reason' => 'inactive'];
                continue;
            }
            if (($storeIds[$id] ?? []) === []) {
                $skipped[] = ['subject' => $subject, 'reason' => 'not assigned to any store view'];
                continue;
            }
            if (!in_array($thesaurus['type'], [self::TYPE_SYNONYM, self::TYPE_EXPANSION], true)) {
                $reason = sprintf('unknown thesaurus type "%s"', $thesaurus['type']);
                $skipped[] = ['subject' => $subject, 'reason' => $reason];
                continue;
            }

            $thesaurusGroups = $groups[$id] ?? [];
            foreach ($thesaurusGroups as $termId => $terms) {
                $groupSubject = sprintf('%s group %d', $subject, $termId);
                $typesenseId = sprintf('es-thesaurus-%d-%d', $id, $termId);

                if ($thesaurus['type'] === self::TYPE_SYNONYM) {
                    if (count($terms) < 2) {
                        $skipped[] = [
                            'subject' => sprintf('%s (%s)', $groupSubject, implode(', ', $terms)),
                            'reason' => 'a synonym group with a single term has no effect (in ElasticSuite neither)',
                        ];
                        continue;
                    }
                    $definitions[] = $this->definition(
                        $typesenseId,
                        SynonymInterface::TYPE_MULTI,
                        null,
                        $terms,
                        $storeIds[$id],
                        $groupSubject,
                    );
                    continue;
                }

                $root = $references[$id][$termId] ?? '';
                if ($root === '') {
                    $skipped[] = ['subject' => $groupSubject, 'reason' => 'an expansion without a reference term'];
                    continue;
                }
                $expansions = array_values(array_diff($terms, [$root]));
                if ($expansions === []) {
                    $skipped[] = ['subject' => $groupSubject, 'reason' => 'an expansion without expanded terms'];
                    continue;
                }
                $definitions[] = $this->definition(
                    $typesenseId,
                    SynonymInterface::TYPE_ONE_WAY,
                    $root,
                    $expansions,
                    $storeIds[$id],
                    $groupSubject,
                );
            }
        }

        return ['definitions' => $definitions, 'skipped' => $skipped];
    }

    /**
     * @param list<array{thesaurus_id: int, term_id: int, term: string}> $rows
     * @return array<int, array<int, list<string>>> unique, trimmed terms by thesaurus id and term id
     */
    private function groupTerms(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $term = trim($row['term']);
            if ($term === '') {
                continue;
            }
            $groups[$row['thesaurus_id']][$row['term_id']][] = $term;
        }

        foreach ($groups as $thesaurusId => $termGroups) {
            ksort($termGroups);
            $groups[$thesaurusId] = array_map(
                static fn (array $terms): array => array_values(array_unique($terms)),
                $termGroups,
            );
        }

        return $groups;
    }

    /**
     * @param list<string> $synonyms
     * @param list<int> $storeIds
     * @return array{typesense_id: string, type: string, root: string|null, synonyms: list<string>, store_ids: list<int>, label: string}
     */
    private function definition(
        string $typesenseId,
        string $type,
        ?string $root,
        array $synonyms,
        array $storeIds,
        string $label,
    ): array {
        return [
            'typesense_id' => $typesenseId,
            'type' => $type,
            'root' => $root,
            'synonyms' => $synonyms,
            'store_ids' => $storeIds,
            'label' => $label,
        ];
    }
}
