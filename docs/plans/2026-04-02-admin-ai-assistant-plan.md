# Admin AI Assistant — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an AI-powered RAG assistant to the Magento admin that answers natural language questions about the entire store (products, orders, customers, CMS, config).

**Architecture:** Typesense multi-collection RAG with a dedicated admin conversation model (`rar-admin-assistant`). Four new indexers (order, customer, store, system_config) follow existing patterns. A global admin slideout panel with chat UI communicates via an ACL-protected AJAX controller that performs server-side `multi_search` with `conversation=true`.

**Tech Stack:** PHP 8.3, Magento 2 (Mage-OS), Typesense PHP SDK v6, RequireJS (admin JS), PHPUnit 10.5

**Design Doc:** `docs/plans/2026-04-02-admin-ai-assistant-design.md`

---

## Task 1: Configuration & ACL Foundation

Add admin config section, ACL resource, and config accessor methods. Everything else depends on this.

**Files:**
- Modify: `etc/acl.xml`
- Modify: `etc/adminhtml/system.xml`
- Modify: `etc/config.xml`
- Modify: `Model/Config/TypeSenseConfig.php`
- Modify: `Api/TypeSenseConfigInterface.php`
- Test: `Test/Unit/Model/Config/TypeSenseConfigTest.php`

**Step 1: Add ACL resource**

In `etc/acl.xml`, add inside `RunAsRoot_TypeSense::typesense`:
```xml
<resource id="RunAsRoot_TypeSense::ai_assistant" title="AI Assistant" sortOrder="70"/>
```

**Step 2: Add config section to system.xml**

In `etc/adminhtml/system.xml`, add a new group inside the existing section:
```xml
<group id="admin_assistant" translate="label" type="text"
       sortOrder="80" showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Admin AI Assistant</label>
    <field id="enabled" translate="label comment" type="select"
           sortOrder="10" showInDefault="1" showInWebsite="1" showInStore="1">
        <label>Enable Admin AI Assistant</label>
        <comment>Requires Conversational Search to be enabled with a valid OpenAI API key.</comment>
        <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
    </field>
    <field id="system_prompt" translate="label comment" type="textarea"
           sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
        <label>System Prompt</label>
        <comment>Instructions for the AI assistant. Describe what data it has access to and how it should respond.</comment>
    </field>
    <field id="openai_model" translate="label" type="select"
           sortOrder="30" showInDefault="1" showInWebsite="1" showInStore="1">
        <label>OpenAI Model</label>
        <source_model>RunAsRoot\TypeSense\Model\Config\Source\OpenAiModelSource</source_model>
        <comment>Leave empty to inherit from Conversational Search settings.</comment>
    </field>
    <field id="conversation_ttl" translate="label comment" type="text"
           sortOrder="40" showInDefault="1" showInWebsite="1" showInStore="1">
        <label>Conversation TTL (seconds)</label>
        <comment>How long conversation history is retained. Default: 86400 (24 hours).</comment>
        <validate>validate-digits</validate>
    </field>
</group>
```

**Step 3: Add default config values**

In `etc/config.xml`, add defaults inside `<run_as_root_typesense>`:
```xml
<admin_assistant>
    <enabled>0</enabled>
    <system_prompt>You are a Magento store analytics assistant. You have access to: Products (catalog items with prices, stock, categories, attributes), Categories (product category hierarchy), CMS Pages (content pages), Orders (sales data including amounts, countries, items, payment methods), Customers (customer profiles with order history and lifetime value), Store Config (system configuration and settings). Answer questions accurately based on the search results provided. Format numbers clearly. When discussing revenue, always mention the currency. For time-based questions, note the data scope.</system_prompt>
    <openai_model></openai_model>
    <conversation_ttl>86400</conversation_ttl>
</admin_assistant>
```

**Step 4: Add config interface methods**

In `Api/TypeSenseConfigInterface.php`, add:
```php
public function isAdminAssistantEnabled(?int $storeId = null): bool;
public function getAdminAssistantSystemPrompt(?int $storeId = null): string;
public function getAdminAssistantOpenAiModel(?int $storeId = null): string;
public function getAdminAssistantConversationTtl(?int $storeId = null): int;
```

**Step 5: Write failing tests for config methods**

In `Test/Unit/Model/Config/TypeSenseConfigTest.php`, add test methods:
```php
public function test_is_admin_assistant_enabled_returns_true_when_enabled(): void
{
    $this->scopeConfig->method('isSetFlag')
        ->with('run_as_root_typesense/admin_assistant/enabled', ScopeInterface::SCOPE_STORE, null)
        ->willReturn(true);

    self::assertTrue($this->sut->isAdminAssistantEnabled());
}

public function test_get_admin_assistant_system_prompt_returns_configured_prompt(): void
{
    $prompt = 'You are a helpful assistant.';
    $this->scopeConfig->method('getValue')
        ->with('run_as_root_typesense/admin_assistant/system_prompt', ScopeInterface::SCOPE_STORE, null)
        ->willReturn($prompt);

    self::assertSame($prompt, $this->sut->getAdminAssistantSystemPrompt());
}

public function test_get_admin_assistant_openai_model_falls_back_to_conversational_search(): void
{
    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/admin_assistant/openai_model', ScopeInterface::SCOPE_STORE, null, ''],
            ['run_as_root_typesense/conversational_search/openai_model', ScopeInterface::SCOPE_STORE, null, 'openai/gpt-4o-mini'],
        ]);

    self::assertSame('openai/gpt-4o-mini', $this->sut->getAdminAssistantOpenAiModel());
}

public function test_get_admin_assistant_conversation_ttl_returns_configured_value(): void
{
    $this->scopeConfig->method('getValue')
        ->with('run_as_root_typesense/admin_assistant/conversation_ttl', ScopeInterface::SCOPE_STORE, null)
        ->willReturn('3600');

    self::assertSame(3600, $this->sut->getAdminAssistantConversationTtl());
}
```

**Step 6: Run tests to verify they fail**

Run: `composer run test`
Expected: FAIL — methods don't exist yet

**Step 7: Implement config methods**

In `Model/Config/TypeSenseConfig.php`, add:
```php
public function isAdminAssistantEnabled(?int $storeId = null): bool
{
    return $this->getFlag('admin_assistant/enabled', $storeId);
}

public function getAdminAssistantSystemPrompt(?int $storeId = null): string
{
    return (string) $this->getValue('admin_assistant/system_prompt', $storeId);
}

public function getAdminAssistantOpenAiModel(?int $storeId = null): string
{
    $model = (string) $this->getValue('admin_assistant/openai_model', $storeId);

    if ($model === '') {
        return $this->getOpenAiModel($storeId);
    }

    return $model;
}

public function getAdminAssistantConversationTtl(?int $storeId = null): int
{
    return (int) $this->getValue('admin_assistant/conversation_ttl', $storeId);
}
```

**Step 8: Run tests to verify they pass**

Run: `composer run test`
Expected: PASS

**Step 9: Commit**

```bash
git add etc/acl.xml etc/adminhtml/system.xml etc/config.xml \
    Api/TypeSenseConfigInterface.php Model/Config/TypeSenseConfig.php \
    Test/Unit/Model/Config/TypeSenseConfigTest.php
git commit -m "feat(admin-assistant): add config section, ACL resource, and config accessors"
```

---

## Task 2: Order Schema Provider & Data Builder

Index Magento orders into Typesense. Follows the exact ProductSchemaProvider/ProductDataBuilder pattern.

**Files:**
- Create: `Api/OrderSchemaProviderInterface.php`
- Create: `Model/Indexer/Order/OrderSchemaProvider.php`
- Create: `Model/Indexer/Order/OrderDataBuilder.php`
- Test: `Test/Unit/Model/Indexer/Order/OrderSchemaProviderTest.php`
- Test: `Test/Unit/Model/Indexer/Order/OrderDataBuilderTest.php`

**Step 1: Create OrderSchemaProviderInterface**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface OrderSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array;
}
```

**Step 2: Write failing test for OrderSchemaProvider**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Order;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Order\OrderSchemaProvider;

final class OrderSchemaProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private OrderSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new OrderSchemaProvider($this->config);
    }

    public function test_get_fields_returns_core_order_fields(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('id', $fieldNames);
        self::assertContains('order_id', $fieldNames);
        self::assertContains('increment_id', $fieldNames);
        self::assertContains('grand_total', $fieldNames);
        self::assertContains('shipping_country', $fieldNames);
        self::assertContains('customer_name', $fieldNames);
        self::assertContains('item_names', $fieldNames);
        self::assertNotContains('embedding', $fieldNames);
    }

    public function test_get_fields_includes_embedding_when_admin_assistant_enabled(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(true);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('embedding', $fieldNames);
    }
}
```

**Step 3: Run tests to verify they fail**

Run: `composer run test`
Expected: FAIL — class doesn't exist

**Step 4: Implement OrderSchemaProvider**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use RunAsRoot\TypeSense\Api\OrderSchemaProviderInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;

class OrderSchemaProvider implements OrderSchemaProviderInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        $fields = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'order_id', 'type' => 'int32'],
            ['name' => 'increment_id', 'type' => 'string'],
            ['name' => 'status', 'type' => 'string', 'facet' => true],
            ['name' => 'state', 'type' => 'string', 'facet' => true],
            ['name' => 'customer_email', 'type' => 'string'],
            ['name' => 'customer_name', 'type' => 'string'],
            ['name' => 'customer_group', 'type' => 'string', 'facet' => true],
            ['name' => 'grand_total', 'type' => 'float'],
            ['name' => 'subtotal', 'type' => 'float'],
            ['name' => 'tax_amount', 'type' => 'float'],
            ['name' => 'shipping_amount', 'type' => 'float'],
            ['name' => 'discount_amount', 'type' => 'float'],
            ['name' => 'currency_code', 'type' => 'string', 'facet' => true],
            ['name' => 'payment_method', 'type' => 'string', 'facet' => true],
            ['name' => 'shipping_country', 'type' => 'string', 'facet' => true],
            ['name' => 'shipping_region', 'type' => 'string', 'facet' => true],
            ['name' => 'shipping_city', 'type' => 'string'],
            ['name' => 'shipping_method', 'type' => 'string', 'facet' => true],
            ['name' => 'billing_country', 'type' => 'string', 'facet' => true],
            ['name' => 'billing_region', 'type' => 'string'],
            ['name' => 'item_count', 'type' => 'int32'],
            ['name' => 'item_skus', 'type' => 'string[]'],
            ['name' => 'item_names', 'type' => 'string[]'],
            ['name' => 'created_at', 'type' => 'int64'],
            ['name' => 'updated_at', 'type' => 'int64'],
            ['name' => 'store_id', 'type' => 'int32', 'facet' => true],
        ];

        if ($this->config->isAdminAssistantEnabled()) {
            $fields[] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'embed' => [
                    'from' => ['increment_id', 'customer_name', 'item_names', 'shipping_country', 'status'],
                    'model_config' => [
                        'model_name' => 'ts/all-MiniLM-L12-v2',
                    ],
                ],
            ];
        }

        return $fields;
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `composer run test`
Expected: PASS

**Step 6: Write failing test for OrderDataBuilder**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Order;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\Order\OrderDataBuilder;

final class OrderDataBuilderTest extends TestCase
{
    private OrderCollectionFactory&MockObject $collectionFactory;
    private GroupRepositoryInterface&MockObject $groupRepository;
    private OrderDataBuilder $sut;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->sut = new OrderDataBuilder($this->collectionFactory, $this->groupRepository);
    }

    public function test_build_returns_complete_order_document(): void
    {
        $shippingAddress = $this->createMock(OrderAddressInterface::class);
        $shippingAddress->method('getCountryId')->willReturn('DE');
        $shippingAddress->method('getRegion')->willReturn('Bavaria');
        $shippingAddress->method('getCity')->willReturn('Munich');

        $billingAddress = $this->createMock(OrderAddressInterface::class);
        $billingAddress->method('getCountryId')->willReturn('DE');
        $billingAddress->method('getRegion')->willReturn('Bavaria');

        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getSku')->willReturn('SKU-001');
        $item->method('getName')->willReturn('Test Product');
        $item->method('getParentItemId')->willReturn(null);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->with(1)->willReturn($group);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn('42');
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getStatus')->willReturn('complete');
        $order->method('getState')->willReturn('complete');
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getCustomerFirstname')->willReturn('John');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getCustomerGroupId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(149.99);
        $order->method('getSubtotal')->willReturn(129.99);
        $order->method('getTaxAmount')->willReturn(20.00);
        $order->method('getShippingAmount')->willReturn(5.99);
        $order->method('getDiscountAmount')->willReturn(-5.99);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getShippingDescription')->willReturn('Flat Rate - Fixed');
        $order->method('getShippingAddress')->willReturn($shippingAddress);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getItems')->willReturn([$item]);
        $order->method('getTotalItemCount')->willReturn(1);
        $order->method('getCreatedAt')->willReturn('2026-01-15 10:30:00');
        $order->method('getUpdatedAt')->willReturn('2026-01-15 10:30:00');
        $order->method('getStoreId')->willReturn(1);

        $result = $this->sut->build($order, 1);

        self::assertSame('order_42', $result['id']);
        self::assertSame(42, $result['order_id']);
        self::assertSame('100000042', $result['increment_id']);
        self::assertSame('complete', $result['status']);
        self::assertSame('John Doe', $result['customer_name']);
        self::assertSame(149.99, $result['grand_total']);
        self::assertSame('DE', $result['shipping_country']);
        self::assertSame('checkmo', $result['payment_method']);
        self::assertSame(['SKU-001'], $result['item_skus']);
        self::assertSame(['Test Product'], $result['item_names']);
    }
}
```

**Step 7: Run tests to verify they fail**

Run: `composer run test`
Expected: FAIL — class doesn't exist

**Step 8: Implement OrderDataBuilder**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class OrderDataBuilder
{
    public function __construct(
        private readonly OrderCollectionFactory $collectionFactory,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(OrderInterface $order, int $storeId): array
    {
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $payment = $order->getPayment();

        $items = [];
        $skus = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId() !== null) {
                continue;
            }
            $skus[] = $item->getSku();
            $items[] = $item->getName();
        }

        $groupName = '';
        try {
            $group = $this->groupRepository->getById($order->getCustomerGroupId());
            $groupName = $group->getCode();
        } catch (\Exception) {
        }

        return [
            'id' => 'order_' . $order->getEntityId(),
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'state' => $order->getState(),
            'customer_email' => (string) $order->getCustomerEmail(),
            'customer_name' => trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()),
            'customer_group' => $groupName,
            'grand_total' => (float) $order->getGrandTotal(),
            'subtotal' => (float) $order->getSubtotal(),
            'tax_amount' => (float) $order->getTaxAmount(),
            'shipping_amount' => (float) $order->getShippingAmount(),
            'discount_amount' => (float) $order->getDiscountAmount(),
            'currency_code' => (string) $order->getOrderCurrencyCode(),
            'payment_method' => $payment ? $payment->getMethod() : '',
            'shipping_country' => $shippingAddress ? (string) $shippingAddress->getCountryId() : '',
            'shipping_region' => $shippingAddress ? (string) $shippingAddress->getRegion() : '',
            'shipping_city' => $shippingAddress ? (string) $shippingAddress->getCity() : '',
            'shipping_method' => (string) $order->getShippingDescription(),
            'billing_country' => $billingAddress ? (string) $billingAddress->getCountryId() : '',
            'billing_region' => $billingAddress ? (string) $billingAddress->getRegion() : '',
            'item_count' => (int) $order->getTotalItemCount(),
            'item_skus' => $skus,
            'item_names' => $items,
            'created_at' => $this->toTimestamp($order->getCreatedAt()),
            'updated_at' => $this->toTimestamp($order->getUpdatedAt()),
            'store_id' => (int) $order->getStoreId(),
        ];
    }

    public function getOrderCollection(array $entityIds, int $storeId): OrderCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);

        if (!empty($entityIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }

    private function toTimestamp(?string $dateTime): int
    {
        if ($dateTime === null || $dateTime === '') {
            return 0;
        }

        return (int) strtotime($dateTime);
    }
}
```

**Step 9: Run tests to verify they pass**

Run: `composer run test`
Expected: PASS

**Step 10: Commit**

```bash
git add Api/OrderSchemaProviderInterface.php \
    Model/Indexer/Order/OrderSchemaProvider.php \
    Model/Indexer/Order/OrderDataBuilder.php \
    Test/Unit/Model/Indexer/Order/OrderSchemaProviderTest.php \
    Test/Unit/Model/Indexer/Order/OrderDataBuilderTest.php
git commit -m "feat(admin-assistant): add Order schema provider and data builder"
```

---

## Task 3: Order Entity Indexer & Registration

Wire the order indexer into the Magento indexing system.

**Files:**
- Create: `Model/Indexer/Order/OrderEntityIndexer.php`
- Create: `Model/Indexer/Order/OrderIndexer.php`
- Modify: `etc/di.xml` (add EntityIndexerPool entry + interface preference)
- Modify: `etc/indexer.xml`
- Modify: `etc/mview.xml`

**Step 1: Create OrderEntityIndexer**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use Magento\Sales\Api\Data\OrderInterface;
use RunAsRoot\TypeSense\Api\OrderSchemaProviderInterface;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerInterface;

class OrderEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private readonly OrderDataBuilder $dataBuilder,
        private readonly OrderSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'order';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_order';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getOrderCollection($entityIds, $storeId);

        foreach ($collection as $order) {
            yield $this->dataBuilder->build($order, $storeId);
        }
    }
}
```

**Step 2: Create OrderIndexer**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use RunAsRoot\TypeSense\Model\Indexer\IndexerOrchestrator;

class OrderIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly IndexerOrchestrator $orchestrator,
    ) {
    }

    public function executeFull(): void
    {
        $this->orchestrator->reindex('order');
    }

    public function executeList(array $ids): void
    {
        $this->orchestrator->reindex('order', array_map('intval', $ids));
    }

    public function executeRow($id): void
    {
        $this->orchestrator->reindex('order', [(int) $id]);
    }

    public function execute($ids): void
    {
        $this->orchestrator->reindex('order', array_map('intval', $ids));
    }
}
```

**Step 3: Register in di.xml**

Add interface preference:
```xml
<preference for="RunAsRoot\TypeSense\Api\OrderSchemaProviderInterface"
            type="RunAsRoot\TypeSense\Model\Indexer\Order\OrderSchemaProvider"/>
```

Add to EntityIndexerPool:
```xml
<item name="order" xsi:type="object">RunAsRoot\TypeSense\Model\Indexer\Order\OrderEntityIndexer</item>
```

**Step 4: Register in indexer.xml**

```xml
<indexer id="typesense_order"
         view_id="typesense_order"
         class="RunAsRoot\TypeSense\Model\Indexer\Order\OrderIndexer">
    <title translate="true">TypeSense Order Index</title>
    <description translate="true">Rebuilds TypeSense order search index</description>
</indexer>
```

**Step 5: Register in mview.xml**

```xml
<view id="typesense_order" class="RunAsRoot\TypeSense\Model\Indexer\Order\OrderIndexer" group="indexer">
    <subscriptions>
        <table name="sales_order" entity_column="entity_id"/>
        <table name="sales_order_address" entity_column="parent_id"/>
        <table name="sales_order_item" entity_column="order_id"/>
        <table name="sales_order_payment" entity_column="parent_id"/>
    </subscriptions>
</view>
```

**Step 6: Run tests**

Run: `composer run test`
Expected: PASS (no regressions)

**Step 7: Commit**

```bash
git add Model/Indexer/Order/OrderEntityIndexer.php \
    Model/Indexer/Order/OrderIndexer.php \
    etc/di.xml etc/indexer.xml etc/mview.xml
git commit -m "feat(admin-assistant): register Order indexer with entity pool, indexer.xml, mview.xml"
```

---

## Task 4: Customer Schema Provider & Data Builder

**Files:**
- Create: `Api/CustomerSchemaProviderInterface.php`
- Create: `Model/Indexer/Customer/CustomerSchemaProvider.php`
- Create: `Model/Indexer/Customer/CustomerDataBuilder.php`
- Test: `Test/Unit/Model/Indexer/Customer/CustomerSchemaProviderTest.php`
- Test: `Test/Unit/Model/Indexer/Customer/CustomerDataBuilderTest.php`

**Step 1: Create CustomerSchemaProviderInterface**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

interface CustomerSchemaProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(): array;
}
```

**Step 2: Write failing test for CustomerSchemaProvider**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\Customer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Customer\CustomerSchemaProvider;

final class CustomerSchemaProviderTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private CustomerSchemaProvider $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->sut = new CustomerSchemaProvider($this->config);
    }

    public function test_get_fields_returns_core_customer_fields(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(false);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('id', $fieldNames);
        self::assertContains('customer_id', $fieldNames);
        self::assertContains('email', $fieldNames);
        self::assertContains('firstname', $fieldNames);
        self::assertContains('lastname', $fieldNames);
        self::assertContains('order_count', $fieldNames);
        self::assertContains('lifetime_value', $fieldNames);
        self::assertNotContains('embedding', $fieldNames);
    }

    public function test_get_fields_includes_embedding_when_enabled(): void
    {
        $this->config->method('isAdminAssistantEnabled')->willReturn(true);

        $fields = $this->sut->getFields();
        $fieldNames = array_column($fields, 'name');

        self::assertContains('embedding', $fieldNames);
    }
}
```

**Step 3: Run tests — expect FAIL**

**Step 4: Implement CustomerSchemaProvider**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Customer;

use RunAsRoot\TypeSense\Api\CustomerSchemaProviderInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;

class CustomerSchemaProvider implements CustomerSchemaProviderInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function getFields(): array
    {
        $fields = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'customer_id', 'type' => 'int32'],
            ['name' => 'email', 'type' => 'string'],
            ['name' => 'firstname', 'type' => 'string'],
            ['name' => 'lastname', 'type' => 'string'],
            ['name' => 'group_id', 'type' => 'int32'],
            ['name' => 'group_name', 'type' => 'string', 'facet' => true],
            ['name' => 'created_at', 'type' => 'int64'],
            ['name' => 'updated_at', 'type' => 'int64'],
            ['name' => 'dob', 'type' => 'string', 'optional' => true],
            ['name' => 'gender', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_billing_country', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_billing_region', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_billing_city', 'type' => 'string', 'optional' => true],
            ['name' => 'default_shipping_country', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_shipping_region', 'type' => 'string', 'optional' => true, 'facet' => true],
            ['name' => 'default_shipping_city', 'type' => 'string', 'optional' => true],
            ['name' => 'order_count', 'type' => 'int32'],
            ['name' => 'lifetime_value', 'type' => 'float'],
            ['name' => 'last_order_date', 'type' => 'int64', 'optional' => true],
            ['name' => 'store_id', 'type' => 'int32', 'facet' => true],
            ['name' => 'website_id', 'type' => 'int32', 'facet' => true],
            ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
        ];

        if ($this->config->isAdminAssistantEnabled()) {
            $fields[] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'embed' => [
                    'from' => ['email', 'firstname', 'lastname', 'group_name'],
                    'model_config' => [
                        'model_name' => 'ts/all-MiniLM-L12-v2',
                    ],
                ],
            ];
        }

        return $fields;
    }
}
```

**Step 5: Implement CustomerDataBuilder**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class CustomerDataBuilder
{
    public function __construct(
        private readonly CustomerCollectionFactory $collectionFactory,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(CustomerInterface $customer, int $storeId): array
    {
        $groupName = '';
        try {
            $group = $this->groupRepository->getById($customer->getGroupId());
            $groupName = $group->getCode();
        } catch (\Exception) {
        }

        $orderStats = $this->getOrderStats((int) $customer->getId());

        $billingAddress = $customer->getDefaultBilling() ? $this->findAddress($customer, $customer->getDefaultBilling()) : null;
        $shippingAddress = $customer->getDefaultShipping() ? $this->findAddress($customer, $customer->getDefaultShipping()) : null;

        $document = [
            'id' => 'customer_' . $customer->getId(),
            'customer_id' => (int) $customer->getId(),
            'email' => $customer->getEmail(),
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
            'group_id' => (int) $customer->getGroupId(),
            'group_name' => $groupName,
            'created_at' => $this->toTimestamp($customer->getCreatedAt()),
            'updated_at' => $this->toTimestamp($customer->getUpdatedAt()),
            'order_count' => (int) $orderStats['count'],
            'lifetime_value' => (float) $orderStats['lifetime_value'],
            'store_id' => (int) $customer->getStoreId(),
            'website_id' => (int) $customer->getWebsiteId(),
            'is_active' => true,
        ];

        if ($customer->getDob()) {
            $document['dob'] = $customer->getDob();
        }

        if ($customer->getGender()) {
            $genderMap = [1 => 'Male', 2 => 'Female', 3 => 'Not Specified'];
            $document['gender'] = $genderMap[(int) $customer->getGender()] ?? '';
        }

        if ($billingAddress) {
            $document['default_billing_country'] = (string) $billingAddress->getCountryId();
            $document['default_billing_region'] = (string) $billingAddress->getRegion()->getRegion();
            $document['default_billing_city'] = (string) $billingAddress->getCity();
        }

        if ($shippingAddress) {
            $document['default_shipping_country'] = (string) $shippingAddress->getCountryId();
            $document['default_shipping_region'] = (string) $shippingAddress->getRegion()->getRegion();
            $document['default_shipping_city'] = (string) $shippingAddress->getCity();
        }

        if ($orderStats['last_order_date']) {
            $document['last_order_date'] = $this->toTimestamp($orderStats['last_order_date']);
        }

        return $document;
    }

    public function getCustomerCollection(array $entityIds, int $storeId): CustomerCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_id', $storeId);

        if (!empty($entityIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        return $collection;
    }

    /** @return array{count: int, lifetime_value: float, last_order_date: ?string} */
    private function getOrderStats(int $customerId): array
    {
        $orderCollection = $this->orderCollectionFactory->create();
        $orderCollection->addFieldToFilter('customer_id', $customerId);
        $orderCollection->addFieldToSelect(['grand_total', 'created_at']);
        $orderCollection->setOrder('created_at', 'DESC');

        $count = $orderCollection->getSize();
        $lifetimeValue = 0.0;
        $lastOrderDate = null;

        foreach ($orderCollection as $order) {
            $lifetimeValue += (float) $order->getGrandTotal();
            if ($lastOrderDate === null) {
                $lastOrderDate = $order->getCreatedAt();
            }
        }

        return [
            'count' => $count,
            'lifetime_value' => $lifetimeValue,
            'last_order_date' => $lastOrderDate,
        ];
    }

    private function findAddress(CustomerInterface $customer, string $addressId): ?\Magento\Customer\Api\Data\AddressInterface
    {
        foreach ($customer->getAddresses() as $address) {
            if ((string) $address->getId() === $addressId) {
                return $address;
            }
        }

        return null;
    }

    private function toTimestamp(?string $dateTime): int
    {
        if ($dateTime === null || $dateTime === '') {
            return 0;
        }

        return (int) strtotime($dateTime);
    }
}
```

**Step 6: Write CustomerDataBuilder test (similar pattern to OrderDataBuilder)**

Follow the same mock setup pattern as `OrderDataBuilderTest` — mock `CustomerInterface`, addresses, group repository, order collection. Assert the document structure.

**Step 7: Run tests — expect PASS**

**Step 8: Commit**

```bash
git add Api/CustomerSchemaProviderInterface.php \
    Model/Indexer/Customer/CustomerSchemaProvider.php \
    Model/Indexer/Customer/CustomerDataBuilder.php \
    Test/Unit/Model/Indexer/Customer/
git commit -m "feat(admin-assistant): add Customer schema provider and data builder"
```

---

## Task 5: Customer Entity Indexer & Registration

Same pattern as Task 3 but for customers.

**Files:**
- Create: `Model/Indexer/Customer/CustomerEntityIndexer.php`
- Create: `Model/Indexer/Customer/CustomerIndexer.php`
- Modify: `etc/di.xml`
- Modify: `etc/indexer.xml`
- Modify: `etc/mview.xml`

**Step 1: Create CustomerEntityIndexer**

Follow `OrderEntityIndexer` pattern. Entity type: `'customer'`, indexer code: `'typesense_customer'`. Use `CustomerDataBuilder` and `CustomerSchemaProviderInterface`.

Note: `buildDocuments` needs to load customers via the Customer API (not just the flat collection) to get addresses. Use `CustomerRepositoryInterface` to load full customer data with addresses.

**Step 2: Create CustomerIndexer**

Follow `OrderIndexer` pattern. Delegates to `$this->orchestrator->reindex('customer', ...)`.

**Step 3: Register in di.xml**

- Preference: `CustomerSchemaProviderInterface` → `CustomerSchemaProvider`
- Pool entry: `<item name="customer">...CustomerEntityIndexer</item>`

**Step 4: Register in indexer.xml**

```xml
<indexer id="typesense_customer"
         view_id="typesense_customer"
         class="RunAsRoot\TypeSense\Model\Indexer\Customer\CustomerIndexer">
    <title translate="true">TypeSense Customer Index</title>
    <description translate="true">Rebuilds TypeSense customer search index</description>
</indexer>
```

**Step 5: Register in mview.xml**

```xml
<view id="typesense_customer" class="RunAsRoot\TypeSense\Model\Indexer\Customer\CustomerIndexer" group="indexer">
    <subscriptions>
        <table name="customer_entity" entity_column="entity_id"/>
        <table name="customer_address_entity" entity_column="parent_id"/>
    </subscriptions>
</view>
```

**Step 6: Run tests — expect PASS**

**Step 7: Commit**

```bash
git add Model/Indexer/Customer/ etc/di.xml etc/indexer.xml etc/mview.xml
git commit -m "feat(admin-assistant): register Customer indexer"
```

---

## Task 6: Store Schema Provider, Data Builder & Indexer

Simpler indexer — stores/websites rarely change. No embedding needed.

**Files:**
- Create: `Api/StoreSchemaProviderInterface.php`
- Create: `Model/Indexer/Store/StoreSchemaProvider.php`
- Create: `Model/Indexer/Store/StoreDataBuilder.php`
- Create: `Model/Indexer/Store/StoreEntityIndexer.php`
- Create: `Model/Indexer/Store/StoreIndexer.php`
- Test: `Test/Unit/Model/Indexer/Store/StoreSchemaProviderTest.php`
- Modify: `etc/di.xml`, `etc/indexer.xml`, `etc/mview.xml`

**Step 1: Create StoreSchemaProvider**

Static schema — no config dependency needed:
```php
public function getFields(): array
{
    return [
        ['name' => 'id', 'type' => 'string'],
        ['name' => 'store_id', 'type' => 'int32'],
        ['name' => 'store_code', 'type' => 'string'],
        ['name' => 'store_name', 'type' => 'string'],
        ['name' => 'website_id', 'type' => 'int32'],
        ['name' => 'website_code', 'type' => 'string'],
        ['name' => 'website_name', 'type' => 'string'],
        ['name' => 'group_id', 'type' => 'int32'],
        ['name' => 'group_name', 'type' => 'string'],
        ['name' => 'root_category_id', 'type' => 'int32'],
        ['name' => 'base_url', 'type' => 'string'],
        ['name' => 'base_currency', 'type' => 'string'],
        ['name' => 'default_locale', 'type' => 'string'],
        ['name' => 'is_active', 'type' => 'bool', 'facet' => true],
    ];
}
```

**Step 2: Create StoreDataBuilder**

Constructor takes `StoreRepositoryInterface`, `StoreManagerInterface`, `ScopeConfigInterface`. Build method takes store ID, loads store/website/group data, resolves base_url and currency from scope config.

**Step 3: Create StoreEntityIndexer + StoreIndexer**

Follow same pattern. Entity type: `'store'`, code: `'typesense_store'`.

Note: `buildDocuments` is different — stores don't have a "collection" to iterate. Instead, iterate `StoreRepositoryInterface::getList()` and filter by store ID.

**Step 4: Register in di.xml, indexer.xml**

No mview subscriptions needed for stores (they change so rarely — full reindex via cron or manual is sufficient). You can add `<table name="store">` subscription if desired.

**Step 5: Write tests, run, verify**

**Step 6: Commit**

```bash
git add Api/StoreSchemaProviderInterface.php \
    Model/Indexer/Store/ \
    Test/Unit/Model/Indexer/Store/ \
    etc/di.xml etc/indexer.xml etc/mview.xml
git commit -m "feat(admin-assistant): add Store indexer"
```

---

## Task 7: System Config Schema Provider, Data Builder & Indexer

Index non-sensitive system configuration values.

**Files:**
- Create: `Api/SystemConfigSchemaProviderInterface.php`
- Create: `Model/Indexer/SystemConfig/SystemConfigSchemaProvider.php`
- Create: `Model/Indexer/SystemConfig/SystemConfigDataBuilder.php`
- Create: `Model/Indexer/SystemConfig/SystemConfigEntityIndexer.php`
- Create: `Model/Indexer/SystemConfig/SystemConfigIndexer.php`
- Test: `Test/Unit/Model/Indexer/SystemConfig/SystemConfigDataBuilderTest.php`
- Modify: `etc/di.xml`, `etc/indexer.xml`

**Step 1: Create SystemConfigSchemaProvider**

Static schema, no embedding:
```php
public function getFields(): array
{
    return [
        ['name' => 'id', 'type' => 'string'],
        ['name' => 'path', 'type' => 'string'],
        ['name' => 'scope', 'type' => 'string'],
        ['name' => 'scope_id', 'type' => 'int32'],
        ['name' => 'value', 'type' => 'string'],
        ['name' => 'section', 'type' => 'string'],
        ['name' => 'group_field', 'type' => 'string'],
        ['name' => 'field', 'type' => 'string'],
        ['name' => 'label', 'type' => 'string'],
    ];
}
```

**Step 2: Create SystemConfigDataBuilder**

Constructor takes `ResourceConnection` to query `core_config_data` table directly. Key implementation detail — **security filter**:

```php
private const SENSITIVE_PATH_PATTERNS = [
    'password', 'key', 'secret', 'token', 'encryption',
    'credential', 'oauth', 'api_key', 'passphrase',
];

private function isSensitivePath(string $path): bool
{
    $pathLower = strtolower($path);
    foreach (self::SENSITIVE_PATH_PATTERNS as $pattern) {
        if (str_contains($pathLower, $pattern)) {
            return true;
        }
    }
    return false;
}
```

The `buildDocuments` method queries `core_config_data`, filters sensitive paths, and yields documents. Parse path into section/group/field by splitting on `/`.

**Step 3: Write test for sensitive path filtering**

```php
public function test_build_excludes_sensitive_config_paths(): void
{
    // Assert that paths containing 'password', 'key', 'secret', etc. are filtered out
}

public function test_build_includes_non_sensitive_paths(): void
{
    // Assert that normal paths like 'web/secure/base_url' are included
}
```

**Step 4: Create SystemConfigEntityIndexer + SystemConfigIndexer**

Entity type: `'system_config'`, code: `'typesense_system_config'`. No mview subscriptions — config changes are infrequent, manual/cron reindex is fine.

**Step 5: Register in di.xml, indexer.xml**

**Step 6: Run tests, verify, commit**

```bash
git add Api/SystemConfigSchemaProviderInterface.php \
    Model/Indexer/SystemConfig/ \
    Test/Unit/Model/Indexer/SystemConfig/ \
    etc/di.xml etc/indexer.xml
git commit -m "feat(admin-assistant): add System Config indexer with sensitive path filtering"
```

---

## Task 8: Admin Conversation Model Manager

Dedicated conversation model for admin RAG, separate from the frontend model.

**Files:**
- Create: `Model/Conversation/AdminConversationModelManager.php`
- Modify: `Observer/ConversationConfigSave.php` (sync both models on config save)
- Test: `Test/Unit/Model/Conversation/AdminConversationModelManagerTest.php`

**Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Conversation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Conversation\AdminConversationModelManager;

final class AdminConversationModelManagerTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private LoggerInterface&MockObject $logger;
    private AdminConversationModelManager $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sut = new AdminConversationModelManager($this->config, $this->clientFactory, $this->logger);
    }

    public function test_get_model_id_returns_admin_model_id(): void
    {
        self::assertSame('rar-admin-assistant', $this->sut->getModelId());
    }

    public function test_get_model_config_uses_admin_specific_settings(): void
    {
        $this->config->method('getAdminAssistantOpenAiModel')->willReturn('openai/gpt-4o');
        $this->config->method('getOpenAiApiKey')->willReturn('sk-test');
        $this->config->method('getIndexPrefix')->willReturn('test');
        $this->config->method('getAdminAssistantSystemPrompt')->willReturn('You are a store assistant.');
        $this->config->method('getAdminAssistantConversationTtl')->willReturn(3600);

        $result = $this->sut->getModelConfig();

        self::assertSame('rar-admin-assistant', $result['id']);
        self::assertSame('openai/gpt-4o', $result['model_name']);
        self::assertSame('sk-test', $result['api_key']);
        self::assertSame('test_admin_conversation_history', $result['history_collection']);
        self::assertSame('You are a store assistant.', $result['system_prompt']);
        self::assertSame(3600, $result['ttl']);
    }
}
```

**Step 2: Run tests — expect FAIL**

**Step 3: Implement AdminConversationModelManager**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Conversation;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;

class AdminConversationModelManager
{
    private const CONVERSATION_MODEL_ID = 'rar-admin-assistant';

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getModelId(): string
    {
        return self::CONVERSATION_MODEL_ID;
    }

    /** @return array<string, mixed> */
    public function getModelConfig(?int $storeId = null): array
    {
        return [
            'id' => self::CONVERSATION_MODEL_ID,
            'model_name' => $this->config->getAdminAssistantOpenAiModel($storeId),
            'api_key' => $this->config->getOpenAiApiKey($storeId),
            'history_collection' => $this->config->getIndexPrefix($storeId) . '_admin_conversation_history',
            'system_prompt' => $this->config->getAdminAssistantSystemPrompt($storeId),
            'max_bytes' => 16384,
            'ttl' => $this->config->getAdminAssistantConversationTtl($storeId),
        ];
    }

    public function sync(?int $storeId = null): void
    {
        if (!$this->config->isAdminAssistantEnabled($storeId)) {
            $this->delete($storeId);
            return;
        }

        try {
            $client = $this->clientFactory->create($storeId);
            $modelConfig = $this->getModelConfig($storeId);

            try {
                $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->update($modelConfig);
            } catch (\Exception) {
                $client->conversations->getModels()->create($modelConfig);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to sync admin conversation model: ' . $e->getMessage());
        }
    }

    public function delete(?int $storeId = null): void
    {
        try {
            $client = $this->clientFactory->create($storeId);
            $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->delete();
        } catch (\Exception) {
        }
    }
}
```

**Step 4: Update ConversationConfigSave observer to sync both models**

In `Observer/ConversationConfigSave.php`, inject `AdminConversationModelManager` and call `->sync()`:
```php
public function __construct(
    private readonly ConversationModelManager $conversationModelManager,
    private readonly AdminConversationModelManager $adminConversationModelManager,
) {
}

public function execute(Observer $observer): void
{
    $this->conversationModelManager->sync();
    $this->adminConversationModelManager->sync();
}
```

**Step 5: Run tests — expect PASS**

**Step 6: Commit**

```bash
git add Model/Conversation/AdminConversationModelManager.php \
    Observer/ConversationConfigSave.php \
    Test/Unit/Model/Conversation/AdminConversationModelManagerTest.php
git commit -m "feat(admin-assistant): add AdminConversationModelManager and sync on config save"
```

---

## Task 9: Chat Controller (Backend API)

AJAX endpoint that receives admin questions and returns Typesense RAG responses.

**Files:**
- Create: `Controller/Adminhtml/Assistant/Chat.php`
- Test: `Test/Unit/Controller/Adminhtml/Assistant/ChatTest.php`

**Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\Assistant;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\Assistant\Chat;
use RunAsRoot\TypeSense\Model\Collection\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Conversation\AdminConversationModelManager;

final class ChatTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private JsonFactory&MockObject $jsonFactory;
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private TypeSenseConfigInterface&MockObject $config;
    private AdminConversationModelManager&MockObject $modelManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private LoggerInterface&MockObject $logger;
    private Chat $sut;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->modelManager = $this->createMock(AdminConversationModelManager::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Note: Actual constructor will need Magento Context — test may need adjustment
        // to account for the parent Action class dependencies. Focus on testing the
        // multi_search call assembly and response parsing.
    }

    public function test_execute_returns_error_when_query_is_empty(): void
    {
        // Test that empty query returns error JSON
    }

    public function test_execute_builds_multi_search_across_all_collections(): void
    {
        // Test that the controller builds search requests for all entity types
    }
}
```

**Step 2: Run tests — expect FAIL**

**Step 3: Implement Chat controller**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Assistant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Collection\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Conversation\AdminConversationModelManager;
use RunAsRoot\TypeSense\Model\Indexer\EntityIndexerPool;

class Chat extends Action
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::ai_assistant';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly TypeSenseConfigInterface $config,
        private readonly AdminConversationModelManager $modelManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly EntityIndexerPool $entityIndexerPool,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $query = (string) $this->getRequest()->getParam('query', '');
            $conversationId = (string) $this->getRequest()->getParam('conversation_id', '');

            if (trim($query) === '') {
                return $result->setData(['success' => false, 'error' => 'Query cannot be empty.']);
            }

            $storeId = (int) $this->storeManager->getDefaultStoreView()->getId();
            $storeCode = $this->storeManager->getDefaultStoreView()->getCode();

            $searchRequests = $this->buildSearchRequests($storeCode, $storeId, $query);

            $commonParams = [
                'conversation' => true,
                'conversation_model_id' => $this->modelManager->getModelId(),
            ];

            if ($conversationId !== '') {
                $commonParams['conversation_id'] = $conversationId;
            }

            $client = $this->clientFactory->create($storeId);
            $response = $client->multiSearch->perform(
                ['searches' => $searchRequests],
                $commonParams,
            );

            $answer = $response['conversation']['answer'] ?? '';
            $newConversationId = $response['conversation']['conversation_id'] ?? $conversationId;

            return $result->setData([
                'success' => true,
                'answer' => $answer,
                'conversation_id' => $newConversationId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Admin AI Assistant error: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'error' => 'Failed to get AI response. Please try again.',
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function buildSearchRequests(string $storeCode, int $storeId, string $query): array
    {
        $entityConfigs = [
            'product' => 'name,description,sku,short_description',
            'category' => 'name,description',
            'cms_page' => 'title,content',
            'order' => 'increment_id,customer_name,customer_email,item_names,shipping_country,status',
            'customer' => 'email,firstname,lastname,group_name,default_shipping_country',
            'store' => 'store_name,website_name,store_code',
            'system_config' => 'path,label,value',
        ];

        $requests = [];
        foreach ($entityConfigs as $entityType => $queryBy) {
            if (!$this->entityIndexerPool->hasIndexer($entityType)) {
                continue;
            }

            $collectionName = $this->collectionNameResolver->resolve($entityType, $storeCode, $storeId);
            $requests[] = [
                'collection' => $collectionName,
                'q' => $query,
                'query_by' => $queryBy,
            ];
        }

        return $requests;
    }
}
```

**Step 4: Run tests — expect PASS**

**Step 5: Add admin route**

Create `etc/adminhtml/routes.xml` if not already existing, or verify existing route. The controller path is `typesense/assistant/chat` which maps to the existing `typesense` frontname.

**Step 6: Commit**

```bash
git add Controller/Adminhtml/Assistant/Chat.php \
    Test/Unit/Controller/Adminhtml/Assistant/ChatTest.php
git commit -m "feat(admin-assistant): add Chat AJAX controller with multi_search across all collections"
```

---

## Task 10: Admin UI — Global Button & Slideout Panel

The frontend-facing piece: floating AI button, slideout panel, chat interface.

**Files:**
- Create: `view/adminhtml/layout/default.xml` (or modify if exists)
- Create: `view/adminhtml/templates/assistant/button.phtml`
- Create: `view/adminhtml/templates/assistant/chat.phtml`
- Create: `view/adminhtml/web/js/assistant.js`
- Create: `view/adminhtml/web/css/assistant.css`
- Create: `ViewModel/Adminhtml/AssistantViewModel.php`

**Step 1: Create AssistantViewModel**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use RunAsRoot\TypeSense\Api\TypeSenseConfigInterface;

class AssistantViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isAdminAssistantEnabled()
            && $this->config->isConversationalSearchEnabled();
    }

    public function getChatUrl(): string
    {
        // This will be set via block in template
        return '';
    }
}
```

**Step 2: Create/modify default.xml layout**

```xml
<?xml version="1.0"?>
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceContainer name="content">
            <block class="Magento\Framework\View\Element\Template"
                   name="typesense.ai.assistant"
                   template="RunAsRoot_TypeSense::assistant/button.phtml">
                <arguments>
                    <argument name="view_model" xsi:type="object">RunAsRoot\TypeSense\ViewModel\Adminhtml\AssistantViewModel</argument>
                </arguments>
            </block>
        </referenceContainer>
    </body>
</page>
```

**Step 3: Create button.phtml**

```php
<?php
/** @var \Magento\Framework\View\Element\Template $block */
/** @var \RunAsRoot\TypeSense\ViewModel\Adminhtml\AssistantViewModel $viewModel */
$viewModel = $block->getViewModel();

if (!$viewModel->isEnabled()) {
    return;
}

$chatUrl = $block->getUrl('typesense/assistant/chat');
?>

<div id="typesense-ai-assistant-trigger"
     data-chat-url="<?= $block->escapeUrl($chatUrl) ?>">
</div>

<script>
require([
    'RunAsRoot_TypeSense/js/assistant'
], function(assistant) {
    assistant.init({
        chatUrl: '<?= $block->escapeUrl($chatUrl) ?>'
    });
});
</script>
```

**Step 4: Create assistant.js**

```javascript
define([
    'jquery',
    'Magento_Ui/js/modal/modal'
], function($, modal) {
    'use strict';

    var STORAGE_KEY = 'typesense_ai_chat';

    function getState() {
        try {
            var data = sessionStorage.getItem(STORAGE_KEY);
            return data ? JSON.parse(data) : { messages: [], conversationId: '' };
        } catch (e) {
            return { messages: [], conversationId: '' };
        }
    }

    function saveState(state) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {}
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderMarkdown(text) {
        // Basic markdown: bold, lists, code blocks
        return text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>')
            .replace(/\n/g, '<br>');
    }

    function createChatHtml() {
        return '<div class="typesense-chat-container">' +
            '<div class="typesense-chat-messages" id="typesense-chat-messages"></div>' +
            '<div class="typesense-chat-input-area">' +
                '<textarea id="typesense-chat-input" placeholder="Ask about your store..." rows="1"></textarea>' +
                '<button id="typesense-chat-send" class="action-primary" type="button">Send</button>' +
            '</div>' +
            '<div class="typesense-chat-actions">' +
                '<button id="typesense-chat-new" class="action-secondary" type="button">New Chat</button>' +
            '</div>' +
        '</div>';
    }

    function renderMessages(container, messages) {
        container.innerHTML = '';

        if (messages.length === 0) {
            container.innerHTML = '<div class="typesense-chat-welcome">' +
                '<strong>TypeSense AI Assistant</strong><br>' +
                'Ask me anything about your store — products, orders, customers, revenue, configuration.' +
                '</div>';
            return;
        }

        messages.forEach(function(msg) {
            var bubble = document.createElement('div');
            bubble.className = 'typesense-chat-bubble typesense-chat-' + msg.role;

            if (msg.role === 'user') {
                bubble.textContent = msg.content;
            } else {
                bubble.innerHTML = renderMarkdown(msg.content);
            }

            container.appendChild(bubble);
        });

        container.scrollTop = container.scrollHeight;
    }

    return {
        init: function(config) {
            var self = this;
            this.chatUrl = config.chatUrl;

            // Create floating button
            var btn = document.createElement('button');
            btn.id = 'typesense-ai-btn';
            btn.className = 'typesense-ai-floating-btn';
            btn.innerHTML = '<span class="typesense-ai-icon">AI</span>';
            btn.title = 'TypeSense AI Assistant';
            document.body.appendChild(btn);

            // Create modal container
            var modalDiv = document.createElement('div');
            modalDiv.id = 'typesense-ai-modal';
            modalDiv.innerHTML = createChatHtml();
            document.body.appendChild(modalDiv);

            // Load CSS
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = require.toUrl('RunAsRoot_TypeSense/css/assistant.css');
            document.head.appendChild(link);

            // Init Magento slide modal
            var modalWidget = modal({
                type: 'slide',
                title: 'TypeSense AI Assistant',
                modalClass: 'typesense-ai-slideout',
                buttons: []
            }, $(modalDiv));

            btn.addEventListener('click', function() {
                $(modalDiv).modal('openModal');
                var state = getState();
                var container = document.getElementById('typesense-chat-messages');
                renderMessages(container, state.messages);
            });

            // Send message
            $(document).on('click', '#typesense-chat-send', function() {
                self.sendMessage();
            });

            $(document).on('keydown', '#typesense-chat-input', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // New chat
            $(document).on('click', '#typesense-chat-new', function() {
                saveState({ messages: [], conversationId: '' });
                var container = document.getElementById('typesense-chat-messages');
                renderMessages(container, []);
            });
        },

        sendMessage: function() {
            var input = document.getElementById('typesense-chat-input');
            var query = input.value.trim();
            if (!query) return;

            var state = getState();
            state.messages.push({ role: 'user', content: query });

            var container = document.getElementById('typesense-chat-messages');
            renderMessages(container, state.messages);
            input.value = '';

            // Show typing indicator
            var typing = document.createElement('div');
            typing.className = 'typesense-chat-bubble typesense-chat-assistant typesense-chat-typing';
            typing.textContent = 'Thinking...';
            container.appendChild(typing);
            container.scrollTop = container.scrollHeight;

            var sendBtn = document.getElementById('typesense-chat-send');
            sendBtn.disabled = true;

            $.ajax({
                url: this.chatUrl,
                method: 'POST',
                data: {
                    query: query,
                    conversation_id: state.conversationId,
                    form_key: window.FORM_KEY
                },
                dataType: 'json',
                success: function(response) {
                    typing.remove();
                    sendBtn.disabled = false;

                    if (response.success) {
                        state.messages.push({ role: 'assistant', content: response.answer });
                        state.conversationId = response.conversation_id;
                    } else {
                        state.messages.push({
                            role: 'assistant',
                            content: 'Error: ' + (response.error || 'Unknown error occurred.')
                        });
                    }

                    saveState(state);
                    renderMessages(container, state.messages);
                },
                error: function() {
                    typing.remove();
                    sendBtn.disabled = false;

                    state.messages.push({
                        role: 'assistant',
                        content: 'Error: Failed to connect. Please try again.'
                    });
                    saveState(state);
                    renderMessages(container, state.messages);
                }
            });
        }
    };
});
```

**Step 5: Create assistant.css**

```css
/* Floating button */
.typesense-ai-floating-btn {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #1979c3;
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s, background 0.2s;
}

.typesense-ai-floating-btn:hover {
    background: #006bb4;
    transform: scale(1.1);
}

.typesense-ai-icon {
    font-weight: 700;
    font-size: 16px;
}

/* Chat container */
.typesense-chat-container {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
}

.typesense-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}

.typesense-chat-welcome {
    text-align: center;
    color: #666;
    padding: 40px 20px;
    line-height: 1.6;
}

/* Chat bubbles */
.typesense-chat-bubble {
    max-width: 85%;
    padding: 10px 14px;
    margin-bottom: 10px;
    border-radius: 12px;
    line-height: 1.5;
    word-wrap: break-word;
}

.typesense-chat-user {
    background: #1979c3;
    color: #fff;
    margin-left: auto;
    border-bottom-right-radius: 4px;
}

.typesense-chat-assistant {
    background: #f0f0f0;
    color: #333;
    margin-right: auto;
    border-bottom-left-radius: 4px;
}

.typesense-chat-assistant code {
    background: #e0e0e0;
    padding: 2px 4px;
    border-radius: 3px;
    font-size: 0.9em;
}

.typesense-chat-assistant ul {
    margin: 4px 0;
    padding-left: 20px;
}

.typesense-chat-typing {
    color: #999;
    font-style: italic;
}

/* Input area */
.typesense-chat-input-area {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid #e0e0e0;
    background: #fff;
}

#typesense-chat-input {
    flex: 1;
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 10px 12px;
    resize: none;
    font-size: 14px;
    font-family: inherit;
    outline: none;
}

#typesense-chat-input:focus {
    border-color: #1979c3;
}

#typesense-chat-send {
    white-space: nowrap;
}

#typesense-chat-send:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Actions */
.typesense-chat-actions {
    padding: 8px 16px;
    border-top: 1px solid #f0f0f0;
}

/* Slideout overrides */
.typesense-ai-slideout .modal-inner-wrap {
    width: 420px;
    max-width: 90vw;
}
```

**Step 6: Verify in browser**

Navigate to any admin page, confirm:
- Floating AI button appears bottom-right
- Clicking opens slideout from the right
- Welcome message displayed
- Can type and send messages (will error if Typesense not configured — that's expected)
- sessionStorage persists across page loads
- "New Chat" clears conversation

**Step 7: Commit**

```bash
git add view/adminhtml/layout/default.xml \
    view/adminhtml/templates/assistant/button.phtml \
    view/adminhtml/web/js/assistant.js \
    view/adminhtml/web/css/assistant.css \
    ViewModel/Adminhtml/AssistantViewModel.php
git commit -m "feat(admin-assistant): add global AI button with slideout chat panel"
```

---

## Task 11: Smoke Test & Integration Verification

Verify the full flow works end-to-end in the Warden dev environment.

**Step 1: Run setup:upgrade + di:compile**

From the Mage-OS project root (`/Users/david/Herd/mage-os-typesense`):
```bash
warden env exec php-fpm bin/magento setup:upgrade
warden env exec php-fpm bin/magento setup:di:compile
warden env exec php-fpm bin/magento cache:flush
```

**Step 2: Enable Admin AI Assistant in admin config**

Navigate to `Stores > Configuration > TypeSense > Admin AI Assistant`, set:
- Enabled: Yes
- System Prompt: (use default)
- Conversation TTL: 86400

Save configuration. Verify observer syncs admin conversation model (check logs).

**Step 3: Run new indexers**

```bash
warden env exec php-fpm bin/magento indexer:reindex typesense_order typesense_customer typesense_store typesense_system_config
```

Verify collections created in Typesense dashboard.

**Step 4: Test the chat**

1. Navigate to any admin page
2. Click the AI floating button
3. Ask: "How many orders do I have?"
4. Verify response comes back from Typesense RAG
5. Ask a follow-up: "Which countries do they ship to?"
6. Verify conversation context is maintained
7. Navigate to another admin page
8. Reopen chat — verify conversation history persists

**Step 5: Test ACL**

Create a limited admin role without `RunAsRoot_TypeSense::ai_assistant` permission. Verify the chat endpoint returns 403.

**Step 6: Test security**

Open browser dev tools, verify:
- No server API key in any JS/HTML
- Network requests go to admin controller only (not directly to Typesense)
- No order/customer data in any frontend-accessible endpoint

**Step 7: Final commit with any fixes**

```bash
git add -A
git commit -m "fix(admin-assistant): integration fixes from smoke test"
```

---

## Task Summary

| Task | Description | Depends On |
|------|-------------|------------|
| 1 | Configuration & ACL Foundation | — |
| 2 | Order Schema Provider & Data Builder | 1 |
| 3 | Order Entity Indexer & Registration | 2 |
| 4 | Customer Schema Provider & Data Builder | 1 |
| 5 | Customer Entity Indexer & Registration | 4 |
| 6 | Store Schema Provider, Data Builder & Indexer | 1 |
| 7 | System Config Schema Provider, Data Builder & Indexer | 1 |
| 8 | Admin Conversation Model Manager | 1 |
| 9 | Chat Controller (Backend API) | 3, 5, 6, 7, 8 |
| 10 | Admin UI — Global Button & Slideout Panel | 9 |
| 11 | Smoke Test & Integration Verification | 10 |

**Parallelizable:** Tasks 2-3, 4-5, 6, 7, 8 can all run in parallel after Task 1 completes.
