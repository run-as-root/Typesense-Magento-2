<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class DescribeDatabaseTool implements ToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SqlSandbox $sandbox,
    ) {
    }

    public function getName(): string
    {
        return 'describe_database';
    }

    public function getDescription(): string
    {
        return 'Discover the Magento database schema. List tables, describe columns, or show foreign key relationships. Use this before writing SQL queries to find the correct table and column names.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list_tables', 'describe_table', 'show_relationships'],
                    'description' => 'What to discover about the database schema',
                ],
                'table_name' => [
                    'type' => 'string',
                    'description' => 'Table name (required for describe_table and show_relationships)',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Filter table list by pattern (e.g. "sales_", "customer_")',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): string
    {
        $action = $arguments['action'] ?? '';
        $tableName = $arguments['table_name'] ?? '';
        $filter = $arguments['filter'] ?? '';

        return match ($action) {
            'list_tables' => $this->listTables($filter),
            'describe_table' => $this->describeTable($tableName),
            'show_relationships' => $this->showRelationships($tableName),
            default => json_encode(['error' => 'Unknown action: ' . $action]),
        };
    }

    private function listTables(string $filter): string
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll('SHOW TABLES');

        $tables = [];
        foreach ($rows as $row) {
            $tableName = (string) reset($row);

            if ($this->sandbox->isBlockedTable($tableName)) {
                continue;
            }

            if ($filter !== '' && !str_contains($tableName, $filter)) {
                continue;
            }

            $tables[] = $tableName;
        }

        return json_encode(['tables' => $tables, 'count' => count($tables)]);
    }

    private function describeTable(string $tableName): string
    {
        if ($tableName === '') {
            return json_encode(['error' => 'table_name is required for describe_table action.']);
        }

        if ($this->sandbox->isBlockedTable($tableName)) {
            return json_encode(['error' => 'Access to table "' . $tableName . '" is blocked.']);
        }

        try {
            $connection = $this->resource->getConnection();
            $columns = $connection->fetchAll('DESCRIBE ' . $connection->quoteIdentifier($tableName));

            return json_encode(['table' => $tableName, 'columns' => $columns]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Could not describe table: ' . $e->getMessage()]);
        }
    }

    private function showRelationships(string $tableName): string
    {
        if ($tableName === '') {
            return json_encode(['error' => 'table_name is required for show_relationships action.']);
        }

        if ($this->sandbox->isBlockedTable($tableName)) {
            return json_encode(['error' => 'Access to table "' . $tableName . '" is blocked.']);
        }

        try {
            $connection = $this->resource->getConnection();

            // Foreign keys FROM this table
            $outgoing = $connection->fetchAll(
                'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$tableName]
            );

            // Foreign keys TO this table (reverse lookup)
            $incoming = $connection->fetchAll(
                'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND REFERENCED_TABLE_NAME = ?
                   AND TABLE_NAME IS NOT NULL',
                [$tableName]
            );

            return json_encode([
                'table' => $tableName,
                'references' => $outgoing,
                'referenced_by' => $incoming,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Could not fetch relationships: ' . $e->getMessage()]);
        }
    }
}
