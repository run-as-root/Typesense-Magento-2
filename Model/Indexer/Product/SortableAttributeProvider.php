<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Attributes offered as sort options (`attr:<code>:asc|desc`). Their indexed values are string arrays, which
 * Typesense cannot sort on, so each gets a single-valued companion field `<code>_sort`.
 */
class SortableAttributeProvider
{
    public const string OPTION_PREFIX = 'attr:';
    public const string FIELD_SUFFIX = '_sort';
    public const string TYPE_FLOAT = 'float';
    public const string TYPE_STRING = 'string';

    // A number, optionally followed by a unit without digits: "40.0 %", "0,7 l", "12 Jahre".
    private const string NUMBER_PATTERN = '/^\s*([+-]?\d+(?:[.,]\d+)?)\s*[^\d]*$/u';

    /** @var array<string, AbstractAttribute|null> */
    private array $attributes = [];

    /** @var array<string, self::TYPE_*|null> */
    private array $types = [];

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly EavConfig $eavConfig,
    ) {
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}|null attribute code and direction
     */
    public static function parseOptionKey(string $key): ?array
    {
        if (!preg_match('/^' . preg_quote(self::OPTION_PREFIX, '/') . '([a-z][a-z0-9_]*):(asc|desc)$/', $key, $matches)) {
            return null;
        }

        /** @var 'asc'|'desc' $direction */
        $direction = $matches[2];

        return [$matches[1], $direction];
    }

    public static function getFieldName(string $attributeCode): string
    {
        return $attributeCode . self::FIELD_SUFFIX;
    }

    /**
     * Without a store id the current (during indexing: the emulated) store's sort options apply.
     *
     * @return array<string, self::TYPE_*> attribute code => type of its sort field
     */
    public function getSortableAttributes(?int $storeId = null): array
    {
        $sortable = [];
        $keys = $this->config->getSortOptionKeys($storeId);
        foreach ($keys as $key) {
            $code = self::parseOptionKey($key)[0] ?? null;
            if ($code === null || isset($sortable[$code]) || !$this->isAllowed($code)) {
                continue;
            }

            $type = $this->getType($code);
            if ($type !== null) {
                $sortable[$code] = $type;
            }
        }

        return $sortable;
    }

    /**
     * Composite products sort by their own value; without one, by the lowest value of their children, which
     * the merged values contain.
     *
     * @param array<string, list<string>> $ownAttributes
     * @param array<string, list<string>> $attributes the product's values merged with its children's
     * @return array<string, float|string> sort field => value
     */
    public function buildSortFields(array $ownAttributes, array $attributes, ?int $storeId = null): array
    {
        $fields = [];
        $sortable = $this->getSortableAttributes($storeId);
        foreach ($sortable as $code => $type) {
            $values = ($ownAttributes[$code] ?? []) !== [] ? $ownAttributes[$code] : ($attributes[$code] ?? []);
            $value = $type === self::TYPE_FLOAT ? $this->lowestNumber($values) : $this->lowestString($values);
            if ($value !== null) {
                $fields[self::getFieldName($code)] = $value;
            }
        }

        return $fields;
    }

    public function getStoreLabel(string $attributeCode, int $storeId): ?string
    {
        $attribute = $this->getAttribute($attributeCode);

        return $attribute !== null ? (string) $attribute->getStoreLabel($storeId) : null;
    }

    private function isAllowed(string $code): bool
    {
        return !in_array($code, ProductSchemaProvider::RESERVED_FIELDS, true)
            && !in_array(self::getFieldName($code), ProductSchemaProvider::RESERVED_FIELDS, true);
    }

    /** @return self::TYPE_*|null */
    private function getType(string $code): ?string
    {
        if (array_key_exists($code, $this->types)) {
            return $this->types[$code];
        }

        $attribute = $this->getAttribute($code);

        return $this->types[$code] = $attribute !== null ? $this->detectType($attribute) : null;
    }

    /**
     * The type is fixed in the schema, so it follows the attribute definition: option attributes are numeric
     * when every option label is a number, other attributes when they store numbers.
     *
     * @return self::TYPE_*
     */
    private function detectType(AbstractAttribute $attribute): string
    {
        if (in_array($attribute->getFrontendInput(), ['select', 'multiselect'], true)) {
            $labels = [];
            $options = $attribute->getSource()->getAllOptions();
            foreach ($options as $option) {
                if ((string) ($option['value'] ?? '') !== '') {
                    $labels[] = (string) $option['label'];
                }
            }
            $numeric = $labels !== [] && array_filter($labels, fn (string $label): bool => $this->toNumber($label) === null) === [];

            return $numeric ? self::TYPE_FLOAT : self::TYPE_STRING;
        }

        if ($attribute->getFrontendInput() === 'boolean') {
            return self::TYPE_STRING;
        }

        return in_array($attribute->getBackendType(), ['decimal', 'int'], true) ? self::TYPE_FLOAT : self::TYPE_STRING;
    }

    private function getAttribute(string $code): ?AbstractAttribute
    {
        if (!array_key_exists($code, $this->attributes)) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            $this->attributes[$code] = $attribute instanceof AbstractAttribute && $attribute->getId() ? $attribute : null;
        }

        return $this->attributes[$code];
    }

    /** @param list<string> $values */
    private function lowestNumber(array $values): ?float
    {
        $numbers = array_filter(array_map($this->toNumber(...), $values), static fn (?float $number): bool => $number !== null);

        return $numbers !== [] ? min($numbers) : null;
    }

    /** @param list<string> $values */
    private function lowestString(array $values): ?string
    {
        $strings = array_values(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''));
        if ($strings === []) {
            return null;
        }
        usort($strings, 'strnatcasecmp');

        return $strings[0];
    }

    private function toNumber(string $value): ?float
    {
        return preg_match(self::NUMBER_PATTERN, $value, $matches) ? (float) str_replace(',', '.', $matches[1]) : null;
    }
}
