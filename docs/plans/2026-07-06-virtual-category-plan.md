# Virtual Category Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let a category's product listing be computed automatically from attribute rules ("virtual category") instead of manual assignment, resolved at query time into a Typesense `filter_by` string.

**Architecture:** New custom table (`run_as_root_typesense_category_virtual_rule`) mirrors the existing `CategoryMerchandising` CRUD pattern. A `ConditionsToFilterByCompiler` translates a Magento-Rule-shaped condition tree into `filter_by` syntax. A `CategoryVirtualRuleResolver` recursively resolves a category's effective filter (own rule, merged with virtual root and direct children per the anchor-bleed-up decision), caching the compiled result per category+store. A `VirtualRuleCacheInvalidator` clears that cache up the ancestor chain on save. `CategoryMerchandisingSync` is updated to source its override's `rule.filter_by` from the resolver instead of a hardcoded string, keeping pin/hide compatible with both static and virtual categories with no new Typesense feature required.

**Tech Stack:** PHP 8.3, Magento 2 (Mage-OS), `Magento_CatalogRule` condition engine, Hyva Theme, Alpine.js 3, Typesense JS SDK, PHPUnit 10.5, Playwright

**Design doc:** `docs/plans/2026-07-06-virtual-category-design.md` — read it before starting; this plan implements it directly and does not re-justify decisions already made there.

---

### Task 1: Add `Magento_CatalogRule` dependency and the virtual rule table

**Files:**
- Modify: `etc/module.xml`
- Modify: `etc/db_schema.xml`

**Step 1: Add the module dependency**

In `etc/module.xml`, add to the `<sequence>` (alphabetical position doesn't matter to Magento, but keep the file tidy — add after `Magento_Catalog`):

```xml
<module name="Magento_Catalog"/>
<module name="Magento_CatalogRule"/>
<module name="Magento_Cms"/>
```

**Step 2: Add the table to db_schema.xml**

Add this table block to `etc/db_schema.xml`, after the closing `</table>` of `run_as_root_typesense_query_merchandising` and before the closing `</schema>`:

```xml
<!-- Category Virtual Rule -->
<table name="run_as_root_typesense_category_virtual_rule" resource="default" engine="innodb"
       comment="TypeSense Category Virtual Rules">
    <column xsi:type="int" name="id" unsigned="true" nullable="false" identity="true" comment="ID"/>
    <column xsi:type="int" name="category_id" unsigned="true" nullable="false" comment="Category ID"/>
    <column xsi:type="smallint" name="store_id" unsigned="true" nullable="false" comment="Store ID"/>
    <column xsi:type="boolean" name="is_virtual" nullable="false" default="0" comment="Is Virtual Category"/>
    <column xsi:type="text" name="conditions_serialized" nullable="true" comment="Serialized Rule Conditions (JSON)"/>
    <column xsi:type="int" name="virtual_category_root_id" unsigned="true" nullable="true" comment="Virtual Category Root ID"/>
    <column xsi:type="text" name="compiled_filter_cache" nullable="true" comment="Cached Compiled Typesense filter_by"/>
    <column xsi:type="timestamp" name="created_at" nullable="false" default="CURRENT_TIMESTAMP" comment="Created At"/>
    <column xsi:type="timestamp" name="updated_at" nullable="false" default="CURRENT_TIMESTAMP" on_update="true" comment="Updated At"/>
    <constraint xsi:type="primary" referenceId="PRIMARY">
        <column name="id"/>
    </constraint>
    <constraint xsi:type="unique" referenceId="UNQ_TS_CAT_VIRTUAL_RULE_CAT_STORE">
        <column name="category_id"/>
        <column name="store_id"/>
    </constraint>
    <constraint xsi:type="foreign" referenceId="FK_TS_CAT_VIRTUAL_RULE_STORE"
                table="run_as_root_typesense_category_virtual_rule" column="store_id"
                referenceTable="store" referenceColumn="store_id" onDelete="CASCADE"/>
</table>
```

**Step 3: Commit**

```bash
git add etc/module.xml etc/db_schema.xml
git commit -m "feat(virtual-category): add Magento_CatalogRule dependency and virtual rule table"
```

---

### Task 2: Create the CategoryVirtualRule data interface

**Files:**
- Create: `Api/Data/CategoryVirtualRuleInterface.php`

**Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api\Data;

interface CategoryVirtualRuleInterface
{
    public function getId(): ?int;

    public function getCategoryId(): int;

    public function setCategoryId(int $categoryId): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function isVirtual(): bool;

    public function setIsVirtual(bool $isVirtual): self;

    public function getConditionsSerialized(): ?string;

    public function setConditionsSerialized(?string $conditions): self;

    public function getVirtualCategoryRootId(): ?int;

    public function setVirtualCategoryRootId(?int $rootCategoryId): self;

    public function getCompiledFilterCache(): ?string;

    public function setCompiledFilterCache(?string $filter): self;

    public function getCreatedAt(): ?string;

    public function getUpdatedAt(): ?string;
}
```

**Step 2: Commit**

```bash
git add Api/Data/CategoryVirtualRuleInterface.php
git commit -m "feat(virtual-category): add CategoryVirtualRuleInterface"
```

---

### Task 3: Create the CategoryVirtualRule model and factory

**Files:**
- Create: `Model/VirtualRule/CategoryVirtualRule.php`
- Create: `Model/VirtualRule/CategoryVirtualRuleFactory.php`
- Modify: `etc/di.xml`

**Step 1: Create the model**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\Model\AbstractModel;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;

class CategoryVirtualRule extends AbstractModel implements CategoryVirtualRuleInterface
{
    protected function _construct(): void
    {
        $this->_init(CategoryVirtualRuleResource::class);
    }

    public function getId(): ?int
    {
        $id = $this->getData('id');
        return $id !== null ? (int) $id : null;
    }

    public function getCategoryId(): int
    {
        return (int) $this->getData('category_id');
    }

    public function setCategoryId(int $categoryId): self
    {
        return $this->setData('category_id', $categoryId);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    public function isVirtual(): bool
    {
        return (bool) $this->getData('is_virtual');
    }

    public function setIsVirtual(bool $isVirtual): self
    {
        return $this->setData('is_virtual', $isVirtual);
    }

    public function getConditionsSerialized(): ?string
    {
        $value = $this->getData('conditions_serialized');
        return $value !== null ? (string) $value : null;
    }

    public function setConditionsSerialized(?string $conditions): self
    {
        return $this->setData('conditions_serialized', $conditions);
    }

    public function getVirtualCategoryRootId(): ?int
    {
        $value = $this->getData('virtual_category_root_id');
        return $value !== null ? (int) $value : null;
    }

    public function setVirtualCategoryRootId(?int $rootCategoryId): self
    {
        return $this->setData('virtual_category_root_id', $rootCategoryId);
    }

    public function getCompiledFilterCache(): ?string
    {
        $value = $this->getData('compiled_filter_cache');
        return $value !== null ? (string) $value : null;
    }

    public function setCompiledFilterCache(?string $filter): self
    {
        return $this->setData('compiled_filter_cache', $filter);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData('created_at');
        return $value !== null ? (string) $value : null;
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData('updated_at');
        return $value !== null ? (string) $value : null;
    }
}
```

**Step 2: Create the factory**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\ObjectManagerInterface;

class CategoryVirtualRuleFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): CategoryVirtualRule
    {
        return $this->objectManager->create(CategoryVirtualRule::class, $data);
    }
}
```

**Step 3: Register the DI preference**

In `etc/di.xml`, add after the `CategoryMerchandisingInterface` preference:

```xml
<preference for="RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface"
            type="RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRule"/>
```

**Step 4: Commit**

```bash
git add Model/VirtualRule/CategoryVirtualRule.php Model/VirtualRule/CategoryVirtualRuleFactory.php etc/di.xml
git commit -m "feat(virtual-category): add CategoryVirtualRule model and factory"
```

---

### Task 4: Create the resource model and collection

**Files:**
- Create: `Model/ResourceModel/CategoryVirtualRule.php`
- Create: `Model/ResourceModel/CategoryVirtualRule/Collection.php`
- Create: `Model/ResourceModel/CategoryVirtualRule/CollectionFactory.php`

**Step 1: Create the resource model**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CategoryVirtualRule extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('run_as_root_typesense_category_virtual_rule', 'id');
    }
}
```

**Step 2: Create the collection**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRule;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CategoryVirtualRule::class, CategoryVirtualRuleResource::class);
    }
}
```

**Step 3: Create the collection factory**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule;

use Magento\Framework\ObjectManagerInterface;

class CollectionFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function create(array $data = []): Collection
    {
        return $this->objectManager->create(Collection::class, $data);
    }
}
```

**Step 4: Commit**

```bash
git add Model/ResourceModel/CategoryVirtualRule.php Model/ResourceModel/CategoryVirtualRule/
git commit -m "feat(virtual-category): add CategoryVirtualRule resource model and collection"
```

---

### Task 5: Create the repository interface and implementation

**Files:**
- Create: `Api/CategoryVirtualRuleRepositoryInterface.php`
- Create: `Model/VirtualRule/CategoryVirtualRuleRepository.php`
- Modify: `etc/di.xml`

**Step 1: Create the repository interface**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;

interface CategoryVirtualRuleRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): CategoryVirtualRuleInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(CategoryVirtualRuleInterface $entity): CategoryVirtualRuleInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(CategoryVirtualRuleInterface $entity): bool;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Convenience lookup used by the resolver and cache invalidator.
     */
    public function findByCategoryAndStore(int $categoryId, int $storeId): ?CategoryVirtualRuleInterface;
}
```

**Step 2: Create the repository implementation**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule\CollectionFactory;

class CategoryVirtualRuleRepository implements CategoryVirtualRuleRepositoryInterface
{
    public function __construct(
        private readonly CategoryVirtualRuleFactory $factory,
        private readonly CategoryVirtualRuleResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function getById(int $id): CategoryVirtualRuleInterface
    {
        $entity = $this->factory->create();
        $this->resource->load($entity, $id);

        if (!$entity->getId()) {
            throw new NoSuchEntityException(__('Entity with id "%1" does not exist.', $id));
        }

        return $entity;
    }

    public function save(CategoryVirtualRuleInterface $entity): CategoryVirtualRuleInterface
    {
        try {
            $this->resource->save($entity);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save entity: %1', $e->getMessage()), $e);
        }

        return $entity;
    }

    public function delete(CategoryVirtualRuleInterface $entity): bool
    {
        try {
            $this->resource->delete($entity);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete entity: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function findByCategoryAndStore(int $categoryId, int $storeId): ?CategoryVirtualRuleInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('category_id', $categoryId)
            ->addFilter('store_id', $storeId)
            ->create();

        $items = array_values($this->getList($searchCriteria)->getItems());

        return $items[0] ?? null;
    }
}
```

**Step 3: Register the DI preference**

In `etc/di.xml`, add after the `CategoryMerchandisingRepositoryInterface` preference:

```xml
<preference for="RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface"
            type="RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleRepository"/>
```

**Step 4: Commit**

```bash
git add Api/CategoryVirtualRuleRepositoryInterface.php Model/VirtualRule/CategoryVirtualRuleRepository.php etc/di.xml
git commit -m "feat(virtual-category): add CategoryVirtualRuleRepository"
```

---

### Task 6: Unit tests for the repository

**Files:**
- Create: `Test/Unit/Model/VirtualRule/CategoryVirtualRuleRepositoryTest.php`

**Step 1: Write the tests**

Mirrors `Test/Unit/Model/Merchandising/CategoryMerchandisingRepositoryTest.php` exactly, plus two tests for `findByCategoryAndStore`.

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule as CategoryVirtualRuleResource;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule\Collection;
use RunAsRoot\TypeSense\Model\ResourceModel\CategoryVirtualRule\CollectionFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRule;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleRepository;

final class CategoryVirtualRuleRepositoryTest extends TestCase
{
    private CategoryVirtualRuleFactory&MockObject $factory;
    private CategoryVirtualRuleResource&MockObject $resource;
    private CollectionFactory&MockObject $collectionFactory;
    private SearchResultsInterfaceFactory&MockObject $searchResultsFactory;
    private CollectionProcessorInterface&MockObject $collectionProcessor;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private CategoryVirtualRuleRepository $sut;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(CategoryVirtualRuleFactory::class);
        $this->resource = $this->createMock(CategoryVirtualRuleResource::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->searchResultsFactory = $this->createMock(SearchResultsInterfaceFactory::class);
        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);

        $this->sut = new CategoryVirtualRuleRepository(
            $this->factory,
            $this->resource,
            $this->collectionFactory,
            $this->searchResultsFactory,
            $this->collectionProcessor,
            $this->searchCriteriaBuilder,
        );
    }

    public function test_get_by_id_returns_entity_when_found(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);
        $entity->method('getId')->willReturn(1);

        $this->factory->method('create')->willReturn($entity);
        $this->resource->expects(self::once())->method('load')->with($entity, 1);

        self::assertSame($entity, $this->sut->getById(1));
    }

    public function test_get_by_id_throws_when_not_found(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);
        $entity->method('getId')->willReturn(null);

        $this->factory->method('create')->willReturn($entity);
        $this->resource->method('load')->with($entity, 99);

        $this->expectException(NoSuchEntityException::class);

        $this->sut->getById(99);
    }

    public function test_save_persists_entity(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->expects(self::once())->method('save')->with($entity);

        self::assertSame($entity, $this->sut->save($entity));
    }

    public function test_save_throws_could_not_save_exception_on_error(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->method('save')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotSaveException::class);

        $this->sut->save($entity);
    }

    public function test_delete_removes_entity(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->expects(self::once())->method('delete')->with($entity);

        self::assertTrue($this->sut->delete($entity));
    }

    public function test_delete_throws_could_not_delete_exception_on_error(): void
    {
        $entity = $this->createMock(CategoryVirtualRule::class);

        $this->resource->method('delete')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotDeleteException::class);

        $this->sut->delete($entity);
    }

    public function test_get_list_returns_search_results(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $items = [$this->createMock(CategoryVirtualRule::class)];

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn($items);
        $collection->method('getSize')->willReturn(1);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);

        $this->collectionProcessor->expects(self::once())->method('process')->with($searchCriteria, $collection);
        $searchResults->expects(self::once())->method('setSearchCriteria')->with($searchCriteria);
        $searchResults->expects(self::once())->method('setItems')->with($items);
        $searchResults->expects(self::once())->method('setTotalCount')->with(1);

        self::assertSame($searchResults, $this->sut->getList($searchCriteria));
    }

    public function test_find_by_category_and_store_returns_first_match(): void
    {
        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn([$rule]);
        $collection->method('getSize')->willReturn(1);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);
        $searchResults->method('getItems')->willReturn([$rule]);

        self::assertSame($rule, $this->sut->findByCategoryAndStore(10, 1));
    }

    public function test_find_by_category_and_store_returns_null_when_no_match(): void
    {
        $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $collection = $this->createMock(Collection::class);
        $searchResults = $this->createMock(SearchResultsInterface::class);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $this->collectionFactory->method('create')->willReturn($collection);
        $collection->method('getItems')->willReturn([]);
        $collection->method('getSize')->willReturn(0);
        $this->searchResultsFactory->method('create')->willReturn($searchResults);
        $searchResults->method('getItems')->willReturn([]);

        self::assertNull($this->sut->findByCategoryAndStore(10, 1));
    }
}
```

**Step 2: Run tests**

Run: `composer run test`
Expected: All tests PASS.

**Step 3: Commit**

```bash
git add Test/Unit/Model/VirtualRule/CategoryVirtualRuleRepositoryTest.php
git commit -m "test(virtual-category): add unit tests for CategoryVirtualRuleRepository"
```

---

### Task 7: ConditionsToFilterByCompiler (TDD)

This is the core translation logic: a decoded condition tree (the same shape Magento's Rule condition builder produces via `getConditions()->asArray()` — a node either has a `conditions` array key, meaning it's a combine/group, or has `attribute`/`operator`/`value` keys, meaning it's a leaf) becomes a Typesense `filter_by` string.

**Files:**
- Create: `Test/Unit/Model/VirtualRule/ConditionsToFilterByCompilerTest.php`
- Create: `Model/VirtualRule/ConditionsToFilterByCompiler.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

final class ConditionsToFilterByCompilerTest extends TestCase
{
    private ConditionsToFilterByCompiler $sut;

    protected function setUp(): void
    {
        $this->sut = new ConditionsToFilterByCompiler();
    }

    public function test_compiles_equals_operator_with_string_value(): void
    {
        $node = ['attribute' => 'color', 'operator' => '==', 'value' => 'red'];

        self::assertSame('color:=`red`', $this->sut->compile($node));
    }

    public function test_compiles_numeric_value_without_quoting(): void
    {
        $node = ['attribute' => 'price', 'operator' => '<', 'value' => '50'];

        self::assertSame('price:<50', $this->sut->compile($node));
    }

    public function test_compiles_not_equals_operator(): void
    {
        $node = ['attribute' => 'status', 'operator' => '!=', 'value' => 'disabled'];

        self::assertSame('status:!=`disabled`', $this->sut->compile($node));
    }

    public function test_compiles_greater_or_equal_operator(): void
    {
        $node = ['attribute' => 'price', 'operator' => '>=', 'value' => '10'];

        self::assertSame('price:>=10', $this->sut->compile($node));
    }

    public function test_compiles_in_list_operator(): void
    {
        $node = ['attribute' => 'color', 'operator' => '()', 'value' => 'red,blue,green'];

        self::assertSame('color:[`red`,`blue`,`green`]', $this->sut->compile($node));
    }

    public function test_compiles_not_in_list_operator(): void
    {
        $node = ['attribute' => 'color', 'operator' => '!()', 'value' => 'red,blue'];

        self::assertSame('color:!=[`red`,`blue`]', $this->sut->compile($node));
    }

    public function test_compiles_all_aggregator_with_and(): void
    {
        $node = [
            'aggregator' => 'all',
            'conditions' => [
                ['attribute' => 'color', 'operator' => '==', 'value' => 'red'],
                ['attribute' => 'price', 'operator' => '<', 'value' => '50'],
            ],
        ];

        self::assertSame('(color:=`red`) && (price:<50)', $this->sut->compile($node));
    }

    public function test_compiles_any_aggregator_with_or(): void
    {
        $node = [
            'aggregator' => 'any',
            'conditions' => [
                ['attribute' => 'color', 'operator' => '==', 'value' => 'red'],
                ['attribute' => 'color', 'operator' => '==', 'value' => 'blue'],
            ],
        ];

        self::assertSame('(color:=`red`) || (color:=`blue`)', $this->sut->compile($node));
    }

    public function test_compiles_nested_combine_groups(): void
    {
        $node = [
            'aggregator' => 'all',
            'conditions' => [
                ['attribute' => 'gender', 'operator' => '==', 'value' => 'women'],
                [
                    'aggregator' => 'any',
                    'conditions' => [
                        ['attribute' => 'color', 'operator' => '==', 'value' => 'red'],
                        ['attribute' => 'color', 'operator' => '==', 'value' => 'blue'],
                    ],
                ],
            ],
        ];

        self::assertSame(
            '(gender:=`women`) && ((color:=`red`) || (color:=`blue`))',
            $this->sut->compile($node),
        );
    }

    public function test_empty_combine_compiles_to_no_match_filter(): void
    {
        $node = ['aggregator' => 'all', 'conditions' => []];

        self::assertSame(ConditionsToFilterByCompiler::NO_MATCH_FILTER, $this->sut->compile($node));
    }

    public function test_boolean_false_value_is_not_dropped(): void
    {
        // Guards against the known ElasticSuite bug class where falsy condition
        // values get silently treated as "empty" during compilation.
        $node = ['attribute' => 'is_active', 'operator' => '==', 'value' => false];

        self::assertSame('is_active:=false', $this->sut->compile($node));
    }

    public function test_string_zero_value_is_not_dropped(): void
    {
        $node = ['attribute' => 'qty', 'operator' => '==', 'value' => '0'];

        self::assertSame('qty:=0', $this->sut->compile($node));
    }

    public function test_missing_attribute_throws_exception(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sut->compile(['attribute' => '', 'operator' => '==', 'value' => 'red']);
    }

    public function test_unsupported_operator_throws_exception(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sut->compile(['attribute' => 'color', 'operator' => '~~', 'value' => 'red']);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `composer run test -- --filter=ConditionsToFilterByCompilerTest`
Expected: FAIL — class `ConditionsToFilterByCompiler` not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Framework\Exception\LocalizedException;

class ConditionsToFilterByCompiler
{
    public const NO_MATCH_FILTER = 'id:=__typesense_virtual_category_no_match__';

    private const CHILDREN_KEY = 'conditions';

    private const OPERATOR_MAP = [
        '==' => ':=',
        '!=' => ':!=',
        '>=' => ':>=',
        '<=' => ':<=',
        '>'  => ':>',
        '<'  => ':<',
    ];

    /**
     * @param array<string, mixed> $node A combine node (has a "conditions" array) or a leaf
     *     node (has "attribute"/"operator"/"value"), matching Magento\Rule's own
     *     getConditions()->asArray() shape.
     */
    public function compile(array $node): string
    {
        if (array_key_exists(self::CHILDREN_KEY, $node)) {
            return $this->compileCombine($node);
        }

        return $this->compileLeaf($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function compileCombine(array $node): string
    {
        $children = $node[self::CHILDREN_KEY] ?? [];

        if ($children === []) {
            return self::NO_MATCH_FILTER;
        }

        $aggregator = ($node['aggregator'] ?? 'all') === 'any' ? '||' : '&&';

        $parts = array_map(
            fn(array $child): string => '(' . $this->compile($child) . ')',
            $children,
        );

        return implode(" {$aggregator} ", $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function compileLeaf(array $node): string
    {
        $attribute = (string) ($node['attribute'] ?? '');
        $operator = (string) ($node['operator'] ?? '==');
        $value = $node['value'] ?? null;

        // Deliberately checks null/'' only — NOT empty()/truthiness — so that
        // false, 0, and "0" condition values are preserved.
        if ($attribute === '' || $value === null || $value === '') {
            throw new LocalizedException(__('Virtual category condition is missing an attribute or value.'));
        }

        if (in_array($operator, ['()', '!()'], true)) {
            return $this->compileInOperator($attribute, $operator, $value);
        }

        if (!isset(self::OPERATOR_MAP[$operator])) {
            throw new LocalizedException(__('Unsupported virtual category operator "%1".', $operator));
        }

        return $attribute . self::OPERATOR_MAP[$operator] . $this->formatValue($value);
    }

    private function compileInOperator(string $attribute, string $operator, mixed $value): string
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $formatted = implode(',', array_map(
            fn($v): string => $this->formatValue(trim((string) $v)),
            $values,
        ));
        $prefix = $operator === '!()' ? ':!=' : ':';

        return "{$attribute}{$prefix}[{$formatted}]";
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $stringValue = (string) $value;

        if ($stringValue === 'true' || $stringValue === 'false') {
            return $stringValue;
        }

        return '`' . str_replace('`', '', $stringValue) . '`';
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `composer run test -- --filter=ConditionsToFilterByCompilerTest`
Expected: PASS (14 tests).

**Step 5: Commit**

```bash
git add Model/VirtualRule/ConditionsToFilterByCompiler.php Test/Unit/Model/VirtualRule/ConditionsToFilterByCompilerTest.php
git commit -m "feat(virtual-category): add ConditionsToFilterByCompiler"
```

---

### Task 8: CategoryVirtualRuleResolver (TDD)

Resolves a category's effective `filter_by`: static categories return `category_ids:={id}`; virtual categories compile their own rule, merge in a virtual root (if set) via `&&`, and merge in direct children's effective filters via `||` (anchor bleed-up), caching the result.

**Files:**
- Create: `Test/Unit/Model/VirtualRule/CategoryVirtualRuleResolverTest.php`
- Create: `Model/VirtualRule/CategoryVirtualRuleResolver.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
use RunAsRoot\TypeSense\Model\VirtualRule\ConditionsToFilterByCompiler;

final class CategoryVirtualRuleResolverTest extends TestCase
{
    private CategoryVirtualRuleRepositoryInterface&MockObject $ruleRepository;
    private ConditionsToFilterByCompiler&MockObject $compiler;
    private CategoryCollectionFactory&MockObject $categoryCollectionFactory;
    private Json&MockObject $json;
    private CategoryVirtualRuleResolver $sut;

    protected function setUp(): void
    {
        $this->ruleRepository = $this->createMock(CategoryVirtualRuleRepositoryInterface::class);
        $this->compiler = $this->createMock(ConditionsToFilterByCompiler::class);
        $this->categoryCollectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $this->json = $this->createMock(Json::class);

        $this->sut = new CategoryVirtualRuleResolver(
            $this->ruleRepository,
            $this->compiler,
            $this->categoryCollectionFactory,
            $this->json,
        );
    }

    private function mockChildIds(array $ids): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturn($ids);
        $this->categoryCollectionFactory->method('create')->willReturn($collection);
    }

    public function test_static_category_returns_category_ids_filter(): void
    {
        $this->ruleRepository->method('findByCategoryAndStore')->with(10, 1)->willReturn(null);

        self::assertSame('category_ids:=10', $this->sut->resolveFilter(10, 1));
    }

    public function test_non_virtual_rule_returns_category_ids_filter(): void
    {
        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('isVirtual')->willReturn(false);
        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);

        self::assertSame('category_ids:=10', $this->sut->resolveFilter(10, 1));
    }

    public function test_virtual_category_with_no_children_compiles_own_rule_only(): void
    {
        $this->mockChildIds([]);

        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('isVirtual')->willReturn(true);
        $rule->method('getCategoryId')->willReturn(10);
        $rule->method('getCompiledFilterCache')->willReturn(null);
        $rule->method('getConditionsSerialized')->willReturn('{"attribute":"color","operator":"==","value":"red"}');
        $rule->method('getVirtualCategoryRootId')->willReturn(null);

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);
        $this->json->method('unserialize')->willReturn(['attribute' => 'color', 'operator' => '==', 'value' => 'red']);
        $this->compiler->method('compile')->willReturn('color:=`red`');

        $rule->expects(self::once())->method('setCompiledFilterCache')->with('color:=`red`');
        $this->ruleRepository->expects(self::once())->method('save')->with($rule);

        self::assertSame('color:=`red`', $this->sut->resolveFilter(10, 1));
    }

    public function test_virtual_category_returns_cached_filter_without_recompiling(): void
    {
        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('isVirtual')->willReturn(true);
        $rule->method('getCompiledFilterCache')->willReturn('color:=`red`');

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);
        $this->compiler->expects(self::never())->method('compile');
        $this->ruleRepository->expects(self::never())->method('save');

        self::assertSame('color:=`red`', $this->sut->resolveFilter(10, 1));
    }

    public function test_virtual_category_merges_direct_child_filters(): void
    {
        $this->mockChildIds([20]);

        $parentRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $parentRule->method('isVirtual')->willReturn(true);
        $parentRule->method('getCategoryId')->willReturn(10);
        $parentRule->method('getCompiledFilterCache')->willReturn(null);
        $parentRule->method('getConditionsSerialized')->willReturn('{}');
        $parentRule->method('getVirtualCategoryRootId')->willReturn(null);

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [10, 1, $parentRule],
                [20, 1, null], // child 20 is a plain static category
            ]);

        $this->json->method('unserialize')->willReturn(['aggregator' => 'all', 'conditions' => []]);
        $this->compiler->method('compile')->willReturn(ConditionsToFilterByCompiler::NO_MATCH_FILTER);

        $result = $this->sut->resolveFilter(10, 1);

        self::assertSame(
            '(' . ConditionsToFilterByCompiler::NO_MATCH_FILTER . ') || (category_ids:=20)',
            $result,
        );
    }

    public function test_virtual_category_with_root_merges_root_filter_with_and(): void
    {
        $this->mockChildIds([]);

        $childRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $childRule->method('isVirtual')->willReturn(true);
        $childRule->method('getCategoryId')->willReturn(10);
        $childRule->method('getCompiledFilterCache')->willReturn(null);
        $childRule->method('getConditionsSerialized')->willReturn('{}');
        $childRule->method('getVirtualCategoryRootId')->willReturn(20);

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [10, 1, $childRule],
                [20, 1, null], // root is a plain static category
            ]);

        $this->json->method('unserialize')->willReturn(['aggregator' => 'all', 'conditions' => []]);
        $this->compiler->method('compile')->willReturn('color:=`red`');

        self::assertSame(
            '(category_ids:=20) && (color:=`red`)',
            $this->sut->resolveFilter(10, 1),
        );
    }

    public function test_circular_virtual_root_reference_throws_exception(): void
    {
        $this->mockChildIds([]);

        $ruleA = $this->createMock(CategoryVirtualRuleInterface::class);
        $ruleA->method('isVirtual')->willReturn(true);
        $ruleA->method('getCategoryId')->willReturn(10);
        $ruleA->method('getCompiledFilterCache')->willReturn(null);
        $ruleA->method('getConditionsSerialized')->willReturn('{}');
        $ruleA->method('getVirtualCategoryRootId')->willReturn(20);

        $ruleB = $this->createMock(CategoryVirtualRuleInterface::class);
        $ruleB->method('isVirtual')->willReturn(true);
        $ruleB->method('getCategoryId')->willReturn(20);
        $ruleB->method('getCompiledFilterCache')->willReturn(null);
        $ruleB->method('getConditionsSerialized')->willReturn('{}');
        $ruleB->method('getVirtualCategoryRootId')->willReturn(10);

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [10, 1, $ruleA],
                [20, 1, $ruleB],
            ]);

        $this->json->method('unserialize')->willReturn(['aggregator' => 'all', 'conditions' => []]);
        $this->compiler->method('compile')->willReturn(ConditionsToFilterByCompiler::NO_MATCH_FILTER);

        $this->expectException(LocalizedException::class);

        $this->sut->resolveFilter(10, 1);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `composer run test -- --filter=CategoryVirtualRuleResolverTest`
Expected: FAIL — class `CategoryVirtualRuleResolver` not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;

class CategoryVirtualRuleResolver
{
    public function __construct(
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
        private readonly ConditionsToFilterByCompiler $compiler,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Json $json,
    ) {
    }

    public function resolveFilter(int $categoryId, int $storeId): string
    {
        return $this->resolve($categoryId, $storeId, []);
    }

    /**
     * @param array<int, bool> $visitedPath
     */
    private function resolve(int $categoryId, int $storeId, array $visitedPath): string
    {
        if (isset($visitedPath[$categoryId])) {
            throw new LocalizedException(__(
                'Circular virtual category root reference detected at category %1.',
                $categoryId,
            ));
        }
        $visitedPath[$categoryId] = true;

        $rule = $this->ruleRepository->findByCategoryAndStore($categoryId, $storeId);

        if ($rule === null || !$rule->isVirtual()) {
            return $this->staticFilter($categoryId);
        }

        $cached = $rule->getCompiledFilterCache();
        if ($cached !== null) {
            return $cached;
        }

        $effective = $this->compileEffectiveFilter($rule, $storeId, $visitedPath);

        $rule->setCompiledFilterCache($effective);
        $this->ruleRepository->save($rule);

        return $effective;
    }

    /**
     * @param array<int, bool> $visitedPath
     */
    private function compileEffectiveFilter(
        CategoryVirtualRuleInterface $rule,
        int $storeId,
        array $visitedPath,
    ): string {
        $ownFilter = $this->compiler->compile($this->decodeConditions($rule));

        $rootId = $rule->getVirtualCategoryRootId();
        if ($rootId !== null) {
            $rootFilter = $this->resolve($rootId, $storeId, $visitedPath);
            $ownFilter = "({$rootFilter}) && ({$ownFilter})";
        }

        $childIds = $this->getDirectChildIds($rule->getCategoryId());
        if ($childIds === []) {
            return $ownFilter;
        }

        $childFilters = array_map(
            fn(int $childId): string => '(' . $this->resolve($childId, $storeId, $visitedPath) . ')',
            $childIds,
        );

        return "({$ownFilter}) || " . implode(' || ', $childFilters);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConditions(CategoryVirtualRuleInterface $rule): array
    {
        $serialized = $rule->getConditionsSerialized();
        if ($serialized === null || $serialized === '') {
            return ['aggregator' => 'all', 'conditions' => []];
        }

        return $this->json->unserialize($serialized);
    }

    /**
     * @return int[]
     */
    private function getDirectChildIds(int $categoryId): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToFilter('is_active', 1);
        $collection->addFieldToFilter('parent_id', $categoryId);

        return array_map('intval', $collection->getAllIds());
    }

    private function staticFilter(int $categoryId): string
    {
        return "category_ids:={$categoryId}";
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `composer run test -- --filter=CategoryVirtualRuleResolverTest`
Expected: PASS (7 tests).

**Step 5: Commit**

```bash
git add Model/VirtualRule/CategoryVirtualRuleResolver.php Test/Unit/Model/VirtualRule/CategoryVirtualRuleResolverTest.php
git commit -m "feat(virtual-category): add CategoryVirtualRuleResolver with anchor bleed-up and virtual root"
```

---

### Task 9: VirtualRuleCacheInvalidator (TDD)

Clears the compiled filter cache for a category and every ancestor whenever a rule changes, so the next `resolveFilter()` call recomputes bottom-up instead of returning a stale cached value.

**Files:**
- Create: `Test/Unit/Model/VirtualRule/VirtualRuleCacheInvalidatorTest.php`
- Create: `Model/VirtualRule/VirtualRuleCacheInvalidator.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\VirtualRule;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\VirtualRuleCacheInvalidator;

final class VirtualRuleCacheInvalidatorTest extends TestCase
{
    private CategoryRepositoryInterface&MockObject $categoryRepository;
    private CategoryVirtualRuleRepositoryInterface&MockObject $ruleRepository;
    private VirtualRuleCacheInvalidator $sut;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->ruleRepository = $this->createMock(CategoryVirtualRuleRepositoryInterface::class);

        $this->sut = new VirtualRuleCacheInvalidator(
            $this->categoryRepository,
            $this->ruleRepository,
        );
    }

    public function test_clears_cache_for_category_and_ancestors_with_rules(): void
    {
        // Path: root(1) / grandparent(2) / parent(5) / this category(30)
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/2/5/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        $leafRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $leafRule->method('getCompiledFilterCache')->willReturn('leaf-filter');

        $ancestorRule = $this->createMock(CategoryVirtualRuleInterface::class);
        $ancestorRule->method('getCompiledFilterCache')->willReturn('ancestor-filter');

        $this->ruleRepository->method('findByCategoryAndStore')
            ->willReturnMap([
                [30, 1, $leafRule],
                [2, 1, null],   // no rule row — nothing to clear
                [5, 1, $ancestorRule],
            ]);

        $leafRule->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $ancestorRule->expects(self::once())->method('setCompiledFilterCache')->with(null);
        $this->ruleRepository->expects(self::exactly(2))->method('save');

        $cleared = $this->sut->invalidate(30, 1);

        self::assertSame([30, 5], $cleared);
    }

    public function test_returns_empty_array_when_no_rule_rows_exist(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        $this->ruleRepository->method('findByCategoryAndStore')->willReturn(null);
        $this->ruleRepository->expects(self::never())->method('save');

        self::assertSame([], $this->sut->invalidate(30, 1));
    }

    public function test_skips_categories_whose_cache_is_already_null(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/30');
        $this->categoryRepository->method('get')->with(30)->willReturn($category);

        $rule = $this->createMock(CategoryVirtualRuleInterface::class);
        $rule->method('getCompiledFilterCache')->willReturn(null);
        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);

        $rule->expects(self::never())->method('setCompiledFilterCache');
        $this->ruleRepository->expects(self::never())->method('save');

        self::assertSame([], $this->sut->invalidate(30, 1));
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `composer run test -- --filter=VirtualRuleCacheInvalidatorTest`
Expected: FAIL — class `VirtualRuleCacheInvalidator` not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;

class VirtualRuleCacheInvalidator
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
    ) {
    }

    /**
     * @return int[] IDs of every category whose cache was actually cleared
     *     (self + ancestors that had a rule row with a non-null cache).
     */
    public function invalidate(int $categoryId, int $storeId): array
    {
        $cleared = [];

        foreach ([$categoryId, ...$this->getAncestorIds($categoryId)] as $id) {
            $rule = $this->ruleRepository->findByCategoryAndStore($id, $storeId);

            if ($rule !== null && $rule->getCompiledFilterCache() !== null) {
                $rule->setCompiledFilterCache(null);
                $this->ruleRepository->save($rule);
                $cleared[] = $id;
            }
        }

        return $cleared;
    }

    /**
     * @return int[]
     */
    private function getAncestorIds(int $categoryId): array
    {
        $category = $this->categoryRepository->get($categoryId);
        $pathIds = array_map('intval', explode('/', (string) $category->getPath()));

        // Drop the category itself (last path segment) and the root/store-root placeholders (id <= 1)
        array_pop($pathIds);

        return array_values(array_filter($pathIds, fn(int $id): bool => $id > 1));
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `composer run test -- --filter=VirtualRuleCacheInvalidatorTest`
Expected: PASS (3 tests).

**Step 5: Register as a DI-available service (no interface needed — consumed directly)**

No `di.xml` change required; this class has one implementation and is injected by concrete type, same as `CategoryMerchandisingSync`.

**Step 6: Commit**

```bash
git add Model/VirtualRule/VirtualRuleCacheInvalidator.php Test/Unit/Model/VirtualRule/VirtualRuleCacheInvalidatorTest.php
git commit -m "feat(virtual-category): add VirtualRuleCacheInvalidator for cascading cache clears"
```

---

### Task 10: Update CategoryMerchandisingSync to use the resolver's effective filter

Replaces the hardcoded `category_ids:={id}` override rule with whatever the resolver currently computes for that category — works unchanged for static categories and correctly for virtual ones.

**Files:**
- Modify: `Model/Curation/CategoryMerchandisingSync.php`
- Modify: `Test/Unit/Model/Curation/CategoryMerchandisingSyncTest.php`

**Step 1: Update the failing tests first**

In `CategoryMerchandisingSyncTest.php`, add the new dependency to the imports and `setUp()`:

```php
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
```

```php
private CategoryVirtualRuleResolver&MockObject $resolver;
```

In `setUp()`, add:

```php
$this->resolver = $this->createMock(CategoryVirtualRuleResolver::class);
```

And add it as the last constructor argument:

```php
$this->sut = new CategoryMerchandisingSync(
    $this->overrideManager,
    $this->collectionNameResolver,
    $this->repository,
    $this->searchCriteriaBuilder,
    $this->logger,
    $this->resolver,
);
```

In every existing test method, before calling `$this->sut->sync(...)`, add a stub so the resolver returns the same value the hardcoded string used to produce (keeps all existing assertions valid):

```php
$this->resolver->method('resolveFilter')->with($categoryId, $storeId)->willReturn("category_ids:={$categoryId}");
```

(Use the actual `$categoryId`/`$storeId` variable names already in each test — e.g. `5, 1` in the first test, `3, 2` in the second, `42, 7` in the third, `1, 3` in the fourth, `10, 1` in the fifth.)

Add one new test asserting the resolver's output — not a hardcoded string — is what ends up in the payload:

```php
public function test_sync_uses_resolver_output_as_filter_by_for_virtual_categories(): void
{
    $categoryId = 8;
    $storeId = 1;
    $storeCode = 'default';
    $collectionName = 'rar_products_default';

    $pinRule = $this->createMock(CategoryMerchandisingInterface::class);
    $pinRule->method('getAction')->willReturn('pin');
    $pinRule->method('getProductId')->willReturn(1);
    $pinRule->method('getPosition')->willReturn(1);

    $searchCriteria = $this->createMock(SearchCriteriaInterface::class);
    $searchResults = $this->createMock(SearchResultsInterface::class);
    $searchResults->method('getItems')->willReturn([$pinRule]);

    $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
    $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);
    $this->repository->method('getList')->willReturn($searchResults);
    $this->collectionNameResolver->method('resolve')->willReturn($collectionName);

    $this->resolver->method('resolveFilter')
        ->with($categoryId, $storeId)
        ->willReturn('color:=`red` || category_ids:=9');

    $this->overrideManager->expects(self::once())
        ->method('createOverride')
        ->with(
            $collectionName,
            'cat_merch_8_1',
            self::callback(fn(array $payload): bool =>
                $payload['rule']['filter_by'] === 'color:=`red` || category_ids:=9'),
        );

    $this->sut->sync($categoryId, $storeId, $storeCode);
}
```

**Step 2: Run tests to verify they fail**

Run: `composer run test -- --filter=CategoryMerchandisingSyncTest`
Expected: FAIL — constructor signature mismatch (resolver argument not accepted yet).

**Step 3: Update the implementation**

In `Model/Curation/CategoryMerchandisingSync.php`, add the import and constructor argument:

```php
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
```

```php
public function __construct(
    private readonly OverrideManagerInterface $overrideManager,
    private readonly CollectionNameResolverInterface $collectionNameResolver,
    private readonly CategoryMerchandisingRepositoryInterface $repository,
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    private readonly LoggerInterface $logger,
    private readonly CategoryVirtualRuleResolver $virtualRuleResolver,
) {
}
```

Replace the hardcoded filter line:

```php
'filter_by' => "category_ids:={$categoryId}",
```

with:

```php
'filter_by' => $this->virtualRuleResolver->resolveFilter($categoryId, $storeId),
```

**Step 4: Run tests to verify they pass**

Run: `composer run test -- --filter=CategoryMerchandisingSyncTest`
Expected: PASS (7 tests).

**Step 5: Commit**

```bash
git add Model/Curation/CategoryMerchandisingSync.php Test/Unit/Model/Curation/CategoryMerchandisingSyncTest.php
git commit -m "feat(virtual-category): source merchandising override filter_by from the resolver"
```

---

### Task 11: Wire the resolver into the frontend category page

**Files:**
- Modify: `ViewModel/Frontend/CategorySearchConfigViewModel.php`
- Modify: `Test/Unit/ViewModel/Frontend/CategorySearchConfigViewModelTest.php`
- Modify: `view/frontend/web/js/category-search.js`

**Step 1: Update the failing test first**

In `CategorySearchConfigViewModelTest.php`, add the import and a mock property:

```php
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
```

```php
private CategoryVirtualRuleResolver&MockObject $virtualRuleResolver;
```

In `setUp()`:

```php
$this->virtualRuleResolver = $this->createMock(CategoryVirtualRuleResolver::class);
```

Add it as the last constructor argument:

```php
$this->sut = new CategorySearchConfigViewModel(
    $this->config,
    $this->storeManager,
    $this->collectionNameResolver,
    $this->registry,
    $this->virtualRuleResolver,
);
```

Update `test_get_config_includes_category_id_key` to also stub and assert the new key:

```php
$this->virtualRuleResolver->method('resolveFilter')->with(7, 1)->willReturn('category_ids:=7');
```

```php
self::assertSame('category_ids:=7', $result['categoryFilterBy']);
```

(add this assertion after the existing `categoryId` assertion in that test)

Add a new test for the null-category case:

```php
public function test_get_config_omits_category_filter_when_no_category_in_context(): void
{
    $store = $this->createMock(StoreInterface::class);
    $store->method('getCode')->willReturn('default');
    $store->method('getId')->willReturn(1);
    $this->storeManager->method('getStore')->willReturn($store);

    $this->config->method('getSearchHost')->willReturn('localhost');
    $this->config->method('getSearchPort')->willReturn(8108);
    $this->config->method('getSearchProtocol')->willReturn('http');
    $this->config->method('getSearchOnlyApiKey')->willReturn('xyz');
    $this->config->method('getProductsPerPage')->willReturn(24);
    $this->config->method('getEnabledSortOptions')->willReturn([]);
    $this->config->method('getTileAttributes')->willReturn([]);
    $this->collectionNameResolver->method('resolve')->willReturn('rar_products_default');
    $this->registry->method('registry')->willReturn(null);

    $this->virtualRuleResolver->expects(self::never())->method('resolveFilter');

    $result = $this->sut->getConfig();

    self::assertNull($result['categoryFilterBy']);
}
```

**Step 2: Run tests to verify they fail**

Run: `composer run test -- --filter=CategorySearchConfigViewModelTest`
Expected: FAIL — constructor signature mismatch.

**Step 3: Update the ViewModel**

In `ViewModel/Frontend/CategorySearchConfigViewModel.php`, add the import and constructor argument:

```php
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleResolver;
```

```php
public function __construct(
    private readonly TypeSenseConfigInterface $config,
    private readonly StoreManagerInterface $storeManager,
    private readonly CollectionNameResolverInterface $collectionNameResolver,
    private readonly Registry $registry,
    private readonly CategoryVirtualRuleResolver $virtualRuleResolver,
) {
}
```

Add a resolved-filter getter and wire it into `getConfig()`:

```php
public function getCategoryFilterBy(): ?string
{
    $categoryId = $this->getCurrentCategoryId();
    if ($categoryId === null) {
        return null;
    }

    return $this->virtualRuleResolver->resolveFilter($categoryId, (int) $this->storeManager->getStore()->getId());
}
```

In `getConfig()`, add the new key to the returned array (after `'categoryId'`):

```php
'categoryId'       => $this->getCurrentCategoryId(),
'categoryFilterBy' => $this->getCategoryFilterBy(),
```

**Step 4: Run tests to verify they pass**

Run: `composer run test -- --filter=CategorySearchConfigViewModelTest`
Expected: PASS.

**Step 5: Update category-search.js to use the resolved filter**

In `view/frontend/web/js/category-search.js`, replace:

```js
const filterParts = [`category_ids:=${config.categoryId}`];
```

with:

```js
const filterParts = [config.categoryFilterBy];
```

**Step 6: Run the full unit suite**

Run: `composer run test`
Expected: All tests PASS.

**Step 7: Commit**

```bash
git add ViewModel/Frontend/CategorySearchConfigViewModel.php Test/Unit/ViewModel/Frontend/CategorySearchConfigViewModelTest.php view/frontend/web/js/category-search.js
git commit -m "feat(virtual-category): use resolved filter_by on the frontend category page"
```

---

### Task 12: Admin condition classes restricting attributes to the Typesense schema

Extends Magento's own `CatalogRule` condition classes, overriding only the attribute list so the rule builder can't offer attributes Typesense can't filter on. Everything else (operator rendering, value inputs, AJAX row add/remove) is inherited unchanged from `Magento_CatalogRule`.

**Files:**
- Create: `Model/VirtualRule/Condition/Combine.php`
- Create: `Model/VirtualRule/Condition/Product.php`

**Step 1: Read the classes being extended first**

Before writing these, read `vendor/magento/module-catalog-rule/Model/Rule/Condition/Combine.php` and `vendor/magento/module-catalog-rule/Model/Rule/Condition/Product.php` to confirm the exact signature of `loadAttributeOptions()` in the installed Magento/Mage-OS version — signatures have shifted slightly across 2.4.x releases, and this codebase targets both 2.4.7-p9 and 2.4.8-p4 per the CI matrix (see `Model/README` / `.github/workflows/ci.yml`). Match whatever you find exactly; don't guess.

**Step 2: Create the Combine condition**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\CatalogRule\Model\Rule\Condition\Combine as CatalogRuleCombine;

class Combine extends CatalogRuleCombine
{
    protected function getNewChildSelectOptions(): array
    {
        $productAttributeOptions = $this->getProductAttributeCondition()->loadAttributeOptions()->getAttributeOption();

        $conditions = [];
        foreach ($productAttributeOptions as $code => $label) {
            $conditions[] = [
                'value' => Product::class . '|' . $code,
                'label' => $label,
            ];
        }

        return array_merge_recursive(
            parent::getNewChildSelectOptions(),
            [['value' => 'conditions_combine', 'label' => __('Conditions Combination'), 'children' => []]],
            [['label' => __('Attribute'), 'value' => $conditions]],
        );
    }

    private function getProductAttributeCondition(): Product
    {
        return $this->getConditions()[0] ?? $this->_conditionFactory ?? new Product(
            $this->getContext(),
        );
    }
}
```

> **Note for the implementing engineer:** the exact `getNewChildSelectOptions()` override shape depends on the installed `Magento_CatalogRule` version's own implementation — read it first (Step 1) and adapt this skeleton to match its real structure rather than assuming the above compiles as-is. The behavior that matters is: **the returned attribute list must only include attribute codes present in `TypeSenseConfigInterface::getAdditionalAttributes()` plus the fixed core set** (`name`, `sku`, `price`, `category_ids`) **— nothing else.**

**Step 3: Create the Product leaf condition**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\CatalogRule\Model\Rule\Condition\Product as CatalogRuleProduct;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class Product extends CatalogRuleProduct
{
    private const CORE_FILTERABLE_ATTRIBUTES = ['name', 'sku', 'price', 'category_ids'];

    public function loadAttributeOptions(): self
    {
        parent::loadAttributeOptions();

        /** @var TypeSenseConfigInterface $config */
        $config = \Magento\Framework\App\ObjectManager::getInstance()->get(TypeSenseConfigInterface::class);
        $allowed = array_merge(self::CORE_FILTERABLE_ATTRIBUTES, $config->getAdditionalAttributes());

        $options = $this->getAttributeOption();
        $filtered = array_intersect_key($options, array_flip($allowed));

        $this->setAttributeOption($filtered);

        return $this;
    }
}
```

> `ObjectManager::getInstance()` is used here only because `Magento\Rule\Model\Condition\AbstractCondition` subclasses are instantiated by Magento's own rule-condition factory (`_conditionFactory`), which does not go through this module's constructor-injection path — this is the same reason `Magento\CatalogRule\Model\Rule\Condition\Product` itself and most third-party CatalogRule attribute-restriction extensions use it here. It's an accepted exception to "never use ObjectManager directly," scoped narrowly to this one Magento-imposed instantiation boundary — do not use it anywhere else in this feature.

**Step 4: Commit**

```bash
git add Model/VirtualRule/Condition/
git commit -m "feat(virtual-category): add condition classes restricting attributes to Typesense schema"
```

---

### Task 13: Admin block, category form fieldset, ACL, and routes

Mirrors the existing `typesense_merchandising` fieldset pattern exactly.

**Files:**
- Create: `Block/Adminhtml/Category/VirtualRule.php`
- Create: `view/adminhtml/templates/category/virtual_rule.phtml`
- Modify: `view/adminhtml/ui_component/category_form.xml`
- Modify: `etc/acl.xml`

**Step 1: Create the block**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Category;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class VirtualRule extends Template
{
    protected $_template = 'RunAsRoot_TypeSense::category/virtual_rule.phtml';

    public function __construct(
        Context $context,
        private readonly TypeSenseConfigInterface $config,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }

    public function getCategoryId(): ?int
    {
        $id = $this->getRequest()->getParam('id');

        return $id !== null ? (int) $id : null;
    }

    public function getLoadUrl(): string
    {
        return $this->getUrl('typesense/categoryvirtualrule/load');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('typesense/categoryvirtualrule/save');
    }

    public function getNewConditionHtmlUrl(): string
    {
        return $this->getUrl('typesense/categoryvirtualrule/newConditionHtml');
    }
}
```

**Step 2: Create the template**

This follows the same AJAX load/save shape as `merchandiser.phtml` (read that file first for the exact JS conventions used — fetch calls, CSRF form-key handling, admin styling classes). At minimum it needs:
- A container for Magento's native rule-condition tree (rendered server-side via the `newConditionHtml` AJAX endpoint, same as Catalog Price Rule's conditions tab)
- A "Virtual Category" yes/no toggle (`is_virtual`)
- A "Virtual Category Root" category chooser (optional, sets `virtual_category_root_id`)
- A save button posting the assembled condition tree + toggle + root ID as JSON to `getSaveUrl()`

```php
<?php

declare(strict_types=1);

use Magento\Framework\Escaper;
use RunAsRoot\TypeSense\Block\Adminhtml\Category\VirtualRule;

/** @var Escaper $escaper */
/** @var VirtualRule $block */

if (!$block->isEnabled()) {
    return;
}
?>
<div id="typesense-virtual-rule-container"
     data-category-id="<?= (int) $block->getCategoryId() ?>"
     data-store-id="<?= (int) $block->getStoreId() ?>"
     data-load-url="<?= $escaper->escapeUrl($block->getLoadUrl()) ?>"
     data-save-url="<?= $escaper->escapeUrl($block->getSaveUrl()) ?>"
     data-new-condition-url="<?= $escaper->escapeUrl($block->getNewConditionHtmlUrl()) ?>">
    <div class="field">
        <label class="label"><span><?= $escaper->escapeHtml(__('Virtual Category')) ?></span></label>
        <div class="control">
            <input type="checkbox" id="typesense-is-virtual" name="is_virtual" value="1"/>
        </div>
    </div>
    <div id="typesense-virtual-rule-conditions"></div>
    <button type="button" id="typesense-virtual-rule-save" class="action-primary">
        <?= $escaper->escapeHtml(__('Save Virtual Rule')) ?>
    </button>
</div>
<script>
    require(['jquery'], function ($) {
        // Mirrors the load/save fetch pattern in merchandiser.phtml — see that
        // file for the exact request/response contract and form-key handling.
    });
</script>
```

> This template is intentionally a skeleton — copy the real fetch/render logic from `view/adminhtml/templates/category/merchandiser.phtml` verbatim and adapt the payload shape (condition tree + `is_virtual` + `virtual_category_root_id` instead of the product pin/hide list).

**Step 3: Add the fieldset to category_form.xml**

In `view/adminhtml/ui_component/category_form.xml`, add after the `typesense_merchandising` fieldset:

```xml
<fieldset name="typesense_virtual_category" sortOrder="45">
    <settings>
        <collapsible>true</collapsible>
        <opened>false</opened>
        <label translate="true">TypeSense Virtual Category</label>
    </settings>
    <htmlContent name="typesense_category_virtual_rule">
        <argument name="block" xsi:type="object">RunAsRoot\TypeSense\Block\Adminhtml\Category\VirtualRule</argument>
        <argument name="data" xsi:type="array">
            <item name="config" xsi:type="array">
                <item name="component" xsi:type="string">Magento_Ui/js/form/components/html</item>
            </item>
        </argument>
    </htmlContent>
</fieldset>
```

**Step 4: Add the ACL resource**

In `etc/acl.xml`, add after the `RunAsRoot_TypeSense::overrides` resource:

```xml
<resource id="RunAsRoot_TypeSense::virtual_category" title="Virtual Category" sortOrder="55"/>
```

**Step 5: Commit**

```bash
git add Block/Adminhtml/Category/VirtualRule.php view/adminhtml/templates/category/virtual_rule.phtml view/adminhtml/ui_component/category_form.xml etc/acl.xml
git commit -m "feat(virtual-category): add admin block, template, and category form fieldset"
```

---

### Task 14: Admin Load/Save/NewConditionHtml controllers

Mirrors `Controller/Adminhtml/CategoryMerchandiser/{Load,Save}.php`. `Save` persists the rule, clears the compiled cache for the category and its ancestors via `VirtualRuleCacheInvalidator`, and re-syncs `CategoryMerchandisingSync` for every category whose cache was cleared (so pin/hide overrides stay in lockstep — see Task 10).

**Files:**
- Create: `Controller/Adminhtml/CategoryVirtualRule/Load.php`
- Create: `Controller/Adminhtml/CategoryVirtualRule/Save.php`
- Create: `Test/Unit/Controller/Adminhtml/CategoryVirtualRule/SaveTest.php`

**Step 1: Read the reference implementation**

Read `Controller/Adminhtml/CategoryMerchandiser/Load.php` and `Save.php` in full before writing these — match their exact conventions for `ADMIN_RESOURCE`, JSON payload parsing, and error handling.

**Step 2: Write the Save controller test first**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule\Save;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\VirtualRuleCacheInvalidator;

final class SaveTest extends TestCase
{
    public function test_save_persists_rule_invalidates_cache_and_resyncs_merchandising(): void
    {
        $context = $this->createMock(Context::class);
        $request = $this->createMock(RequestInterface::class);
        $context->method('getRequest')->willReturn($request);

        $request->method('getParam')->with('payload')->willReturn(json_encode([
            'category_id' => 10,
            'store_id' => 1,
            'is_virtual' => true,
            'conditions' => ['aggregator' => 'all', 'conditions' => []],
            'virtual_category_root_id' => null,
        ]));

        $jsonFactory = $this->createMock(JsonFactory::class);
        $resultJson = $this->createMock(Json::class);
        $jsonFactory->method('create')->willReturn($resultJson);
        $resultJson->method('setData')->willReturnSelf();

        $repository = $this->createMock(CategoryVirtualRuleRepositoryInterface::class);
        $repository->method('findByCategoryAndStore')->willReturn(null);

        $entity = $this->createMock(CategoryVirtualRuleInterface::class);
        $factory = $this->createMock(CategoryVirtualRuleFactory::class);
        $factory->method('create')->willReturn($entity);

        $entity->expects(self::once())->method('setCategoryId')->with(10);
        $entity->expects(self::once())->method('setStoreId')->with(1);
        $entity->expects(self::once())->method('setIsVirtual')->with(true);
        $repository->expects(self::once())->method('save')->with($entity);

        $invalidator = $this->createMock(VirtualRuleCacheInvalidator::class);
        $invalidator->expects(self::once())->method('invalidate')->with(10, 1)->willReturn([10, 5]);

        $merchandisingSync = $this->createMock(CategoryMerchandisingSync::class);
        $merchandisingSync->expects(self::exactly(2))->method('sync');

        $logger = $this->createMock(LoggerInterface::class);

        $controller = new Save(
            $context,
            $jsonFactory,
            $repository,
            $factory,
            $invalidator,
            $merchandisingSync,
            $logger,
        );

        $controller->execute();
    }
}
```

**Step 3: Run test to verify it fails**

Run: `composer run test -- --filter=CategoryVirtualRuleSaveTest`
Expected: FAIL — `Save` controller class not found.

**Step 4: Write the Load controller**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;

class Load extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::virtual_category';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CategoryVirtualRuleRepositoryInterface $repository,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();

        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        $storeId = (int) $this->getRequest()->getParam('store_id', 0);

        $rule = $this->repository->findByCategoryAndStore($categoryId, $storeId);

        if ($rule === null) {
            return $resultJson->setData([
                'is_virtual' => false,
                'conditions' => ['aggregator' => 'all', 'conditions' => []],
                'virtual_category_root_id' => null,
            ]);
        }

        return $resultJson->setData([
            'is_virtual' => $rule->isVirtual(),
            'conditions' => json_decode((string) $rule->getConditionsSerialized(), true) ?: ['aggregator' => 'all', 'conditions' => []],
            'virtual_category_root_id' => $rule->getVirtualCategoryRootId(),
        ]);
    }
}
```

**Step 5: Write the Save controller**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\VirtualRuleCacheInvalidator;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::virtual_category';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CategoryVirtualRuleRepositoryInterface $repository,
        private readonly CategoryVirtualRuleFactory $factory,
        private readonly VirtualRuleCacheInvalidator $cacheInvalidator,
        private readonly CategoryMerchandisingSync $merchandisingSync,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();

        try {
            $raw = $this->getRequest()->getParam('payload') ?: $this->getRequest()->getContent();
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $resultJson->setData(['success' => false, 'message' => 'Invalid JSON payload.']);
        }

        $categoryId = (int) ($payload['category_id'] ?? 0);
        $storeId = (int) ($payload['store_id'] ?? 0);

        if ($categoryId === 0) {
            return $resultJson->setData(['success' => false, 'message' => 'category_id is required.']);
        }

        try {
            $rule = $this->repository->findByCategoryAndStore($categoryId, $storeId) ?? $this->factory->create();
            $rule->setCategoryId($categoryId);
            $rule->setStoreId($storeId);
            $rule->setIsVirtual((bool) ($payload['is_virtual'] ?? false));
            $rule->setConditionsSerialized(json_encode($payload['conditions'] ?? ['aggregator' => 'all', 'conditions' => []]));
            $rule->setVirtualCategoryRootId(isset($payload['virtual_category_root_id']) ? (int) $payload['virtual_category_root_id'] : null);
            $rule->setCompiledFilterCache(null); // force recompilation on next resolve

            $this->repository->save($rule);

            $storeCode = $this->_objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)
                ->getStore($storeId)
                ->getCode();

            $clearedCategoryIds = $this->cacheInvalidator->invalidate($categoryId, $storeId);
            foreach ($clearedCategoryIds as $clearedCategoryId) {
                $this->merchandisingSync->sync($clearedCategoryId, $storeId, $storeCode);
            }

            return $resultJson->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('CategoryVirtualRule Save error: ' . $e->getMessage(), ['exception' => $e]);

            return $resultJson->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
```

> `$this->_objectManager` is inherited from `Magento\Backend\App\Action` and used here only for the `StoreManagerInterface` lookup, matching the same pattern already used in `CategoryMerchandiser\Save::execute()` (see that file — it injects `StoreManagerInterface` via the constructor instead; **prefer that approach** and add `StoreManagerInterface $storeManager` as a constructor argument instead of using `_objectManager`, matching the existing convention exactly). This note is a correction to apply while implementing, not a pattern to keep.

**Step 6: Run tests to verify they pass**

Run: `composer run test -- --filter=CategoryVirtualRuleSaveTest`
Expected: PASS.

**Step 7: Add the routes for the new controllers**

The existing `etc/adminhtml/routes.xml` already registers the `typesense` frontName for the whole module — no changes needed. Magento resolves `typesense/categoryvirtualrule/load` and `.../save` to these new controller classes automatically by path convention.

**Step 8: Commit**

```bash
git add Controller/Adminhtml/CategoryVirtualRule/ Test/Unit/Controller/Adminhtml/CategoryVirtualRule/
git commit -m "feat(virtual-category): add admin Load/Save controllers for virtual rules"
```

---

### Task 15: E2E tests

**Files:**
- Create: `tests/e2e/pages/admin/category-virtual-rule.ts`
- Create: `tests/e2e/tests/admin/category-virtual-rule.spec.ts`

**Step 1: Read the existing merchandiser E2E test first**

Read `tests/e2e/pages/admin/category-merchandiser.ts` and `tests/e2e/tests/admin/category-merchandiser.spec.ts` in full — mirror their page-object structure and assertions exactly, adjusted for the virtual rule fieldset's selectors (`#typesense-virtual-rule-container`, `#typesense-is-virtual`, etc. from Task 13).

**Step 2: Write the page object and spec**

Follow the existing pattern: a page-object class with methods like `openCategory(id)`, `enableVirtual()`, `addCondition(attribute, operator, value)`, `save()`; and a spec with cases:
- Toggling "Virtual Category" on reveals the condition builder
- Saving a simple one-condition rule succeeds (assert the success toast/response, matching how `category-merchandiser.spec.ts` asserts save success)
- Frontend: visiting the virtual category's URL shows only products matching the rule (reuse `tests/e2e/pages/frontend/category-page.ts` to read rendered product names, matching `category-page.spec.ts`'s assertion style)

**Step 3: Run the E2E suite**

Run whatever command `tests/e2e/tests/admin/category-merchandiser.spec.ts` is run with today (check `tests/e2e/README.md` or `package.json` in that directory if present) and confirm the new spec passes alongside the existing ones.

**Step 4: Commit**

```bash
git add tests/e2e/pages/admin/category-virtual-rule.ts tests/e2e/tests/admin/category-virtual-rule.spec.ts
git commit -m "test(virtual-category): add e2e coverage for admin rule save and frontend listing"
```

---

### Task 16: Full verification pass

**Step 1: Run the full unit suite**

Run: `composer run test`
Expected: All tests PASS, including every test added in Tasks 6–11 and 14.

**Step 2: PHPCS**

Run: `vendor/bin/phpcs --standard=.phpcs.xml Api/ Model/VirtualRule/ Model/Curation/CategoryMerchandisingSync.php Controller/Adminhtml/CategoryVirtualRule/ Block/Adminhtml/Category/VirtualRule.php ViewModel/Frontend/CategorySearchConfigViewModel.php`
Expected: No errors.

**Step 3: PHPStan**

Run: `vendor/bin/phpstan analyse --configuration=phpstan.neon`
Expected: No new errors. (The `Condition/Combine.php` and `Condition/Product.php` classes from Task 12 may need targeted `ignoreErrors` entries in `phpstan.neon` if they trip the `Magento\CatalogRule\*` base-class analysis — check before adding blanket ignores.)

**Step 4: Fix and commit if needed**

```bash
git add -A
git commit -m "fix(virtual-category): address linting/static analysis issues"
```

---

### Task 17: Manual testing in Warden

**Step 1: Deploy**

```bash
composer update run-as-root/magento2-typesense -W
warden env exec php-fpm bin/magento setup:upgrade
warden env exec redis redis-cli FLUSHALL
warden env exec php-fpm bin/magento setup:di:compile
warden env exec php-fpm bin/magento cache:flush
```

**Step 2: Create a virtual category**

In admin, edit an existing category (or create one), open the "TypeSense Virtual Category" fieldset:
- [ ] Toggle "Virtual Category" on
- [ ] Add a condition (e.g. `color == red`)
- [ ] Save — success response, no errors in `var/log/system.log`

**Step 3: Verify frontend listing**

- [ ] Visit the category's frontend URL
- [ ] Only products matching the rule appear
- [ ] Facets and pagination still work

**Step 4: Verify a category with children (anchor bleed-up)**

- [ ] Make a parent category virtual with a narrow rule (e.g. matching zero products)
- [ ] Give a child category (static, real assignments) some products
- [ ] Confirm the parent's listing includes the child's products too

**Step 5: Verify merchandising still works**

- [ ] Pin/hide a product on a virtual category via the existing merchandiser tab
- [ ] Confirm the pinned product appears first, hidden product is absent
- [ ] Edit the virtual rule and save again — confirm the merchandising pin/hide still applies afterward (this exercises the `VirtualRuleCacheInvalidator` → `CategoryMerchandisingSync` re-sync path from Task 14)

**Step 6: Verify virtual root**

- [ ] Create a second category with "Virtual Category Root" pointing at the first
- [ ] Confirm its listing is the root's matches narrowed by its own (possibly empty) rule

**Step 7: Verify graceful degradation**

- [ ] Temporarily misconfigure a rule to reference a removed attribute (if reachable via UI) or stop the Typesense service
- [ ] Confirm the category page shows an empty state rather than a fatal error, and the error is logged

---

## Summary of all files

**New files:**
- `Api/Data/CategoryVirtualRuleInterface.php`
- `Api/CategoryVirtualRuleRepositoryInterface.php`
- `Model/VirtualRule/CategoryVirtualRule.php`
- `Model/VirtualRule/CategoryVirtualRuleFactory.php`
- `Model/VirtualRule/CategoryVirtualRuleRepository.php`
- `Model/VirtualRule/ConditionsToFilterByCompiler.php`
- `Model/VirtualRule/CategoryVirtualRuleResolver.php`
- `Model/VirtualRule/VirtualRuleCacheInvalidator.php`
- `Model/VirtualRule/Condition/Combine.php`
- `Model/VirtualRule/Condition/Product.php`
- `Model/ResourceModel/CategoryVirtualRule.php`
- `Model/ResourceModel/CategoryVirtualRule/Collection.php`
- `Model/ResourceModel/CategoryVirtualRule/CollectionFactory.php`
- `Block/Adminhtml/Category/VirtualRule.php`
- `Controller/Adminhtml/CategoryVirtualRule/Load.php`
- `Controller/Adminhtml/CategoryVirtualRule/Save.php`
- `view/adminhtml/templates/category/virtual_rule.phtml`
- Corresponding `Test/Unit/...` files for every class above with logic
- `tests/e2e/pages/admin/category-virtual-rule.ts`
- `tests/e2e/tests/admin/category-virtual-rule.spec.ts`

**Modified files:**
- `etc/module.xml` (Magento_CatalogRule dependency)
- `etc/db_schema.xml` (new table)
- `etc/di.xml` (new preferences)
- `etc/acl.xml` (new resource)
- `view/adminhtml/ui_component/category_form.xml` (new fieldset)
- `Model/Curation/CategoryMerchandisingSync.php` + its test (resolver-sourced filter_by)
- `ViewModel/Frontend/CategorySearchConfigViewModel.php` + its test (resolved filter exposed to frontend)
- `view/frontend/web/js/category-search.js` (uses resolved filter instead of building its own)
