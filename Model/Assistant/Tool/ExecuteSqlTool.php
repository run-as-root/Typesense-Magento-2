<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ExecuteSqlTool implements ToolInterface
{
    private const DEFAULT_ROW_LIMIT = 100;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SqlSandbox $sandbox,
    ) {
    }

    public function getName(): string
    {
        return 'execute_sql';
    }

    public function getDescription(): string
    {
        return 'Execute a read-only SELECT query on the Magento MySQL database. Returns up to 100 rows. Use describe_database to explore table/column names first. Many tables and columns are blocked for security (admin_user, oauth_token, authorization_role, integration, vault_payment_token, etc). UNION, subqueries into blocked tables, and SQL comments are not allowed.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The SQL SELECT query to execute. Must start with SELECT.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $query = trim($arguments['query'] ?? '');

        if ($query === '') {
            return json_encode(['error' => 'Query cannot be empty.']);
        }

        try {
            $this->sandbox->validate($query);
        } catch (\InvalidArgumentException $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        // Enforce LIMIT
        if (!preg_match('/\bLIMIT\b/i', $query)) {
            $query .= ' LIMIT ' . self::DEFAULT_ROW_LIMIT;
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll($query);

            // Filter sensitive config data if querying core_config_data
            if (stripos($query, 'core_config_data') !== false) {
                $rows = $this->sandbox->filterSensitiveConfigRows($rows);
            }

            return json_encode([
                'rows' => $rows,
                'count' => count($rows),
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Query execution failed. Please check your SQL syntax and try again.']);
        }
    }
}
