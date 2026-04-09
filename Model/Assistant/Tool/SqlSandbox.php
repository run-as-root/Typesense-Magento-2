<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

class SqlSandbox
{
    private const BLOCKED_TABLES = [
        'admin_user', 'admin_passwords', 'oauth_token', 'oauth_consumer',
        'oauth_token_request_log', 'authorization_role', 'authorization_rule',
        'persistent_session', 'admin_user_session',
    ];

    private const BLOCKED_COLUMNS = ['password_hash', 'rp_token'];

    private const SENSITIVE_CONFIG_PATTERNS = [
        'password', 'key', 'secret', 'token', 'encryption',
        'credential', 'oauth', 'api_key', 'passphrase', 'private',
        'cert', 'auth', 'hash', 'username', 'license',
    ];

    /**
     * Validate a SQL query is safe to execute.
     * @throws \InvalidArgumentException if query is unsafe
     */
    public function validate(string $query): void
    {
        $normalized = trim(strtolower($query));

        // Must start with SELECT
        if (!str_starts_with($normalized, 'select')) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed.');
        }

        // Block write keywords anywhere in query
        $blocked = ['insert ', 'update ', 'delete ', 'drop ', 'alter ', 'truncate ', 'create ', 'grant ', 'revoke '];
        foreach ($blocked as $keyword) {
            if (str_contains($normalized, $keyword)) {
                throw new \InvalidArgumentException('Query contains blocked keyword: ' . trim($keyword));
            }
        }

        // Block sensitive tables
        foreach (self::BLOCKED_TABLES as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $query)) {
                throw new \InvalidArgumentException('Access to table "' . $table . '" is blocked.');
            }
        }

        // Block sensitive columns
        foreach (self::BLOCKED_COLUMNS as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $query)) {
                throw new \InvalidArgumentException('Access to column "' . $column . '" is blocked.');
            }
        }
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
        return in_array(strtolower($table), self::BLOCKED_TABLES, true);
    }
}
