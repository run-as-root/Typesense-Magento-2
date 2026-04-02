<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\SystemConfig;

use Magento\Framework\App\ResourceConnection;

class SystemConfigDataBuilder
{
    private const SENSITIVE_PATH_PATTERNS = [
        'password',
        'key',
        'secret',
        'token',
        'encryption',
        'credential',
        'oauth',
        'api_key',
        'passphrase',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Build and yield Typesense documents for system config rows.
     *
     * @param int[] $entityIds  When empty, all non-sensitive rows are returned.
     * @return iterable<array<string, mixed>>
     */
    public function buildDocuments(int $storeId): iterable
    {
        $rows = $this->getConfigData([], $storeId);

        foreach ($rows as $row) {
            $path = (string) $row['path'];

            if ($this->isSensitivePath($path)) {
                continue;
            }

            $parts = explode('/', $path, 3);
            $section = $parts[0] ?? '';
            $group = $parts[1] ?? '';
            $field = $parts[2] ?? '';

            yield [
                'id' => 'config_' . (int) $row['config_id'],
                'path' => $path,
                'scope' => (string) $row['scope'],
                'scope_id' => (int) $row['scope_id'],
                'value' => (string) ($row['value'] ?? ''),
                'section' => $section,
                'group_field' => $group,
                'field' => $field,
                'label' => $path,
            ];
        }
    }

    /**
     * Fetch raw rows from core_config_data.
     *
     * @param int[] $entityIds  When empty, all rows are returned.
     * @return array<int, array<string, mixed>>
     */
    public function getConfigData(array $entityIds, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('core_config_data');

        $select = $connection->select()->from($table);

        if ($entityIds !== []) {
            $select->where('config_id IN (?)', $entityIds);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $connection->fetchAll($select);

        return $rows;
    }

    protected function isSensitivePath(string $path): bool
    {
        $pathLower = strtolower($path);

        foreach (self::SENSITIVE_PATH_PATTERNS as $pattern) {
            if (str_contains($pathLower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
