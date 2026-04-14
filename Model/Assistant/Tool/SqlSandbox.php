<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class SqlSandbox
{
    private const BLOCKED_TABLES = [
        'admin_user', 'admin_passwords', 'oauth_token', 'oauth_consumer',
        'oauth_token_request_log', 'authorization_role', 'authorization_rule',
        'persistent_session', 'admin_user_session', 'integration',
        'email_template', 'vault_payment_token',
    ];

    private const BLOCKED_COLUMNS = [
        'password_hash', 'rp_token', 'token', 'secret',
        'api_key', 'passphrase', 'private_key',
    ];

    private const SENSITIVE_CONFIG_PATTERNS = [
        'password', 'key', 'secret', 'token', 'encryption',
        'credential', 'oauth', 'api_key', 'passphrase', 'private',
        'cert', 'auth', 'hash', 'username', 'license',
    ];

    /**
     * Keywords that indicate write/dangerous operations.
     * Uses word-boundary regex matching instead of str_contains.
     */
    private const BLOCKED_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'truncate',
        'create', 'grant', 'revoke', 'union', 'into', 'outfile',
        'dumpfile', 'load_file', 'call', 'set', 'prepare', 'execute',
        'deallocate', 'handler', 'rename',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Validate a SQL query is safe to execute.
     * @throws \InvalidArgumentException if query is unsafe
     */
    public function validate(string $query): void
    {
        $this->rejectSqlComments($query);

        $normalized = trim(strtolower($query));

        if (!str_starts_with($normalized, 'select')) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed.');
        }

        $this->rejectBlockedKeywords($normalized);
        $this->rejectBlockedTables($query);
        $this->rejectBlockedColumns($query);
        $this->rejectSensitiveConfigAccess($normalized);
    }

    /**
     * Filter sensitive config rows from results.
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function filterSensitiveConfigRows(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row): bool {
            $path = strtolower((string) ($row['path'] ?? ''));
            if ($path === '') {
                return true;
            }
            foreach (self::SENSITIVE_CONFIG_PATTERNS as $pattern) {
                if (str_contains($path, $pattern)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Check if a table name is on the blocked list.
     */
    public function isBlockedTable(string $table): bool
    {
        $resolvedBlocked = array_map(
            fn(string $t): string => strtolower($this->resource->getTableName($t)),
            self::BLOCKED_TABLES
        );

        return in_array(strtolower($table), $resolvedBlocked, true);
    }

    private function rejectSqlComments(string $query): void
    {
        if (preg_match('/\/\*/', $query) || preg_match('/--/', $query) || preg_match('/#/', $query)) {
            throw new \InvalidArgumentException('SQL comments are not allowed.');
        }
    }

    private function rejectBlockedKeywords(string $normalized): void
    {
        foreach (self::BLOCKED_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalized)) {
                throw new \InvalidArgumentException('Query contains blocked keyword: ' . $keyword);
            }
        }
    }

    private function rejectBlockedTables(string $query): void
    {
        foreach (self::BLOCKED_TABLES as $table) {
            $prefixedTable = $this->resource->getTableName($table);

            // Check both unprefixed and prefixed table names
            foreach ([$table, $prefixedTable] as $name) {
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $query)) {
                    throw new \InvalidArgumentException('Access to table "' . $table . '" is blocked.');
                }
            }
        }
    }

    private function rejectBlockedColumns(string $query): void
    {
        foreach (self::BLOCKED_COLUMNS as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $query)) {
                throw new \InvalidArgumentException('Access to column "' . $column . '" is blocked.');
            }
        }
    }

    /**
     * Block access to core_config_data at validation time to prevent
     * extraction of sensitive values via string functions.
     */
    private function rejectSensitiveConfigAccess(string $normalized): void
    {
        $configTable = strtolower($this->resource->getTableName('core_config_data'));

        if (str_contains($normalized, 'core_config_data') || str_contains($normalized, $configTable)) {
            $dangerousFunctions = ['substr', 'substring', 'mid', 'hex', 'unhex', 'char', 'ord', 'ascii', 'conv'];
            foreach ($dangerousFunctions as $func) {
                if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $normalized)) {
                    throw new \InvalidArgumentException(
                        'String extraction functions are not allowed on config data.'
                    );
                }
            }
        }
    }
}
