<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Listing;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

/**
 * The concrete field names of a collection (or of the collection an alias points to), including the fields a
 * pattern field such as category_position_.* created for the imported documents.
 */
class CollectionFieldProvider
{
    private const string CACHE_KEY_PREFIX = 'run_as_root_typesense_fields_';
    // Spares Typesense a schema request per page render, yet sees the fields of a swapped collection soon.
    private const int CACHE_LIFETIME = 300;

    /** @var array<string, list<string>> */
    private array $fieldNames = [];

    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function hasField(string $collection, string $field): bool
    {
        $this->fieldNames[$collection] ??= $this->loadFieldNames($collection);

        return in_array($field, $this->fieldNames[$collection], true);
    }

    /**
     * @return list<string>
     */
    private function loadFieldNames(string $collection): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $collection;
        $cached = $this->cache->load($cacheKey);
        $decoded = $cached ? json_decode($cached, true) : null;
        if (is_array($decoded)) {
            return array_values(array_map('strval', $decoded));
        }

        try {
            $schema = $this->clientFactory->create()->collections[$collection]->retrieve();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Typesense: could not read the fields of collection %s: %s', $collection, $e->getMessage()),
                ['exception' => $e],
            );

            return [];
        }

        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $names = array_values(array_map(static fn (array $field): string => (string) ($field['name'] ?? ''), $fields));
        $this->cache->save((string) json_encode($names), $cacheKey, [], self::CACHE_LIFETIME);

        return $names;
    }
}
