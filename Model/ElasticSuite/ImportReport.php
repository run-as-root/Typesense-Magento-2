<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ElasticSuite;

final class ImportReport
{
    public const string SYNONYMS = 'synonyms';
    public const string BOOSTS = 'boosting rules';
    public const string POSITIONS = 'category positions';
    public const string HIDES = 'hidden products';
    public const string FILTERS = 'category filters';

    /**
     * @var array<string, array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: array<string, list<string>>,
     *     notes: list<string>,
     * }>
     */
    private array $sections = [];

    public function created(string $section, int $count = 1): void
    {
        $this->section($section);
        $this->sections[$section]['created'] += $count;
    }

    public function updated(string $section, int $count = 1): void
    {
        $this->section($section);
        $this->sections[$section]['updated'] += $count;
    }

    public function unchanged(string $section, int $count = 1): void
    {
        $this->section($section);
        $this->sections[$section]['unchanged'] += $count;
    }

    public function skipped(string $section, string $subject, string $reason): void
    {
        $this->section($section);
        $this->sections[$section]['skipped'][$reason][] = $subject;
    }

    public function note(string $section, string $message): void
    {
        $this->section($section);
        $this->sections[$section]['notes'][] = $message;
    }

    /**
     * Skipped subjects are grouped by reason.
     *
     * @return array<string, array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: array<string, list<string>>,
     *     notes: list<string>,
     * }>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    private function section(string $section): void
    {
        $this->sections[$section] ??= ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => [], 'notes' => []];
    }
}
