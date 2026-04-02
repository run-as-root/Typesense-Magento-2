# Agentic Admin AI Assistant — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the Typesense-only RAG with an OpenAI function-calling agent loop that decides which tools to use (Typesense search, SQL aggregations) based on the question.

**Architecture:** PHP agent loop in Magento using `openai-php/client`. The Chat controller sends user question + tool definitions to OpenAI. OpenAI decides which tools to call. PHP executes the tools and sends results back. Repeat until OpenAI produces a final text answer.

**Tech Stack:** PHP 8.3, Magento 2 (Mage-OS), openai-php/client, Typesense PHP SDK v6, PHPUnit 10.5

**Design Doc:** `docs/plans/2026-04-02-agentic-assistant-design.md`

---

## Task 1: Add OpenAI PHP SDK Dependency

**Files:**
- Modify: `composer.json`

**Step 1: Add openai-php/client to composer.json**

Add `"openai-php/client": "^0.19"` to `require` and `"guzzlehttp/guzzle": "^7.0"` (HTTP transport required by OpenAI SDK):

```json
"require": {
    "php": "^8.3",
    "typesense/typesense-php": "^6.0",
    "openai-php/client": "^0.19",
    "guzzlehttp/guzzle": "^7.0"
}
```

Note: In the Magento context, Guzzle is already available. The module's composer.json declares it for standalone compatibility.

**Step 2: Run composer update in Warden**

From `/Users/david/Herd/mage-os-typesense`:
```bash
warden env exec php-fpm composer update run-as-root/magento2-typesense -W
```

**Step 3: Commit**

```bash
git add composer.json
git commit -m "feat(agentic): add openai-php/client dependency"
```

---

## Task 2: Tool Interface & Registry

Define the tool contract and a registry that generates OpenAI-compatible tool definitions.

**Files:**
- Create: `Model/Assistant/Tool/ToolInterface.php`
- Create: `Model/Assistant/ToolRegistry.php`
- Test: `Test/Unit/Model/Assistant/ToolRegistryTest.php`

**Step 1: Create ToolInterface**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

interface ToolInterface
{
    /** Tool name as used in OpenAI function calling (e.g. 'search_typesense') */
    public function getName(): string;

    /** Human-readable description for OpenAI */
    public function getDescription(): string;

    /**
     * OpenAI-compatible JSON schema for parameters.
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array;

    /**
     * Execute the tool with the given arguments (decoded from OpenAI tool_call).
     * @param array<string, mixed> $arguments
     * @return string JSON-encoded result string for OpenAI tool_result
     */
    public function execute(array $arguments): string;
}
```

**Step 2: Create ToolRegistry**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use RunAsRoot\TypeSense\Model\Assistant\Tool\ToolInterface;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools;

    /** @param array<string, ToolInterface> $tools */
    public function __construct(array $tools = [])
    {
        $this->tools = $tools;
    }

    public function getTool(string $name): ToolInterface
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Tool not found: {$name}");
        }

        return $this->tools[$name];
    }

    /**
     * Generate OpenAI-compatible tool definitions array.
     * @return array<int, array<string, mixed>>
     */
    public function getToolDefinitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParametersSchema(),
                ],
            ];
        }

        return $definitions;
    }
}
```

**Step 3: Write test for ToolRegistry**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ToolInterface;
use RunAsRoot\TypeSense\Model\Assistant\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
    public function test_get_tool_returns_registered_tool(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('test_tool');

        $registry = new ToolRegistry(['test_tool' => $tool]);

        self::assertSame($tool, $registry->getTool('test_tool'));
    }

    public function test_get_tool_throws_on_unknown_tool(): void
    {
        $registry = new ToolRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->getTool('nonexistent');
    }

    public function test_get_tool_definitions_returns_openai_format(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('my_tool');
        $tool->method('getDescription')->willReturn('Does stuff');
        $tool->method('getParametersSchema')->willReturn([
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]);

        $registry = new ToolRegistry(['my_tool' => $tool]);
        $definitions = $registry->getToolDefinitions();

        self::assertCount(1, $definitions);
        self::assertSame('function', $definitions[0]['type']);
        self::assertSame('my_tool', $definitions[0]['function']['name']);
        self::assertSame('Does stuff', $definitions[0]['function']['description']);
        self::assertArrayHasKey('properties', $definitions[0]['function']['parameters']);
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit --filter=ToolRegistryTest --testdox
```

**Step 5: Commit**

```bash
git add Model/Assistant/Tool/ToolInterface.php Model/Assistant/ToolRegistry.php \
    Test/Unit/Model/Assistant/ToolRegistryTest.php
git commit -m "feat(agentic): add ToolInterface and ToolRegistry"
```

---

## Task 3: SearchTypesenseTool

The first tool: searches any Typesense collection with optional filters and sorting.

**Files:**
- Create: `Model/Assistant/Tool/SearchTypesenseTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/SearchTypesenseToolTest.php`

**Step 1: Implement SearchTypesenseTool**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;

class SearchTypesenseTool implements ToolInterface
{
    private const COLLECTION_QUERY_BY = [
        'product' => 'name,description,sku,short_description',
        'order' => 'increment_id,customer_name,customer_email,item_names,shipping_country,status',
        'customer' => 'email,firstname,lastname,group_name,default_shipping_country',
        'category' => 'name,description',
        'cms_page' => 'title,content',
        'store' => 'store_name,website_name,store_code',
        'system_config' => 'path,label,value',
    ];

    public function __construct(
        private readonly TypeSenseClientFactoryInterface $clientFactory,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function getName(): string
    {
        return 'search_typesense';
    }

    public function getDescription(): string
    {
        return 'Search any indexed Typesense collection (product, order, customer, category, cms_page, store, system_config) with optional filters and sorting. Returns matching documents.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection' => [
                    'type' => 'string',
                    'enum' => ['product', 'order', 'customer', 'category', 'cms_page', 'store', 'system_config'],
                    'description' => 'Which collection to search',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query text',
                ],
                'filter_by' => [
                    'type' => 'string',
                    'description' => 'Typesense filter syntax, e.g. "shipping_country:DE" or "status:complete"',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'description' => 'Sort field and direction, e.g. "sales_count:desc", "grand_total:desc", "created_at:desc"',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return (default 10)',
                ],
            ],
            'required' => ['collection', 'query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $collectionType = $arguments['collection'] ?? '';
        $query = $arguments['query'] ?? '*';
        $filterBy = $arguments['filter_by'] ?? '';
        $sortBy = $arguments['sort_by'] ?? '';
        $limit = min((int) ($arguments['limit'] ?? 10), 20);

        if (!isset(self::COLLECTION_QUERY_BY[$collectionType])) {
            return json_encode(['error' => 'Unknown collection: ' . $collectionType]);
        }

        $store = $this->storeManager->getDefaultStoreView();
        $storeId = (int) $store->getId();
        $storeCode = $store->getCode();

        $collectionName = $this->collectionNameResolver->resolve($collectionType, $storeCode, $storeId);
        $queryBy = self::COLLECTION_QUERY_BY[$collectionType];

        $searchParams = [
            'q' => $query,
            'query_by' => $queryBy,
            'per_page' => $limit,
            'exclude_fields' => 'embedding',
        ];

        if ($filterBy !== '') {
            $searchParams['filter_by'] = $filterBy;
        }

        if ($sortBy !== '') {
            $searchParams['sort_by'] = $sortBy;
        }

        $client = $this->clientFactory->create($storeId);
        $result = $client->collections[$collectionName]->documents->search($searchParams);

        $documents = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $documents[] = $hit['document'];
        }

        return json_encode([
            'found' => $result['found'] ?? 0,
            'documents' => $documents,
        ]);
    }
}
```

**Step 2: Write test** — test `getName`, `getParametersSchema` structure, and that `execute` calls the client correctly (mock the Typesense client).

**Step 3: Run tests, commit**

```bash
git add Model/Assistant/Tool/SearchTypesenseTool.php Test/Unit/Model/Assistant/Tool/
git commit -m "feat(agentic): add SearchTypesenseTool"
```

---

## Task 4: QueryOrdersTool

SQL aggregation tool for order analytics.

**Files:**
- Create: `Model/Assistant/Tool/QueryOrdersTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/QueryOrdersToolTest.php`

**Step 1: Implement QueryOrdersTool**

Constructor takes `ResourceConnection`. The `execute()` method switches on the `aggregation` parameter and runs the corresponding pre-built query. All queries use parameterized inputs.

Key aggregations:
- `total_revenue`: `SELECT SUM(grand_total) as total, order_currency_code as currency FROM sales_order WHERE grand_total > 0 GROUP BY order_currency_code`
- `revenue_by_country`: `SELECT soa.country_id, COUNT(*) as order_count, SUM(so.grand_total) as total_revenue FROM sales_order so JOIN sales_order_address soa ON soa.parent_id = so.entity_id AND soa.address_type = 'shipping' GROUP BY soa.country_id ORDER BY total_revenue DESC`
- `top_customers_by_revenue`: `SELECT customer_email, customer_firstname, customer_lastname, SUM(grand_total) as total_spent, COUNT(*) as order_count FROM sales_order WHERE customer_id IS NOT NULL GROUP BY customer_id ORDER BY total_spent DESC LIMIT ?`
- `order_count_by_status`: `SELECT status, COUNT(*) as count FROM sales_order GROUP BY status`
- `avg_order_value`: `SELECT AVG(grand_total) as avg_value, order_currency_code FROM sales_order WHERE grand_total > 0 GROUP BY order_currency_code`
- `orders_by_month`: `SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as order_count, SUM(grand_total) as total FROM sales_order GROUP BY month ORDER BY month DESC`

Apply optional filters (country, status, date_from, date_to) via WHERE clauses with bound parameters.

**Step 2: Write tests** — test each aggregation returns the correct SQL structure. Mock ResourceConnection and verify the query patterns.

**Step 3: Commit**

```bash
git add Model/Assistant/Tool/QueryOrdersTool.php Test/Unit/Model/Assistant/Tool/
git commit -m "feat(agentic): add QueryOrdersTool with SQL aggregations"
```

---

## Task 5: QueryCustomersTool & QueryProductsTool

Two more SQL aggregation tools.

**Files:**
- Create: `Model/Assistant/Tool/QueryCustomersTool.php`
- Create: `Model/Assistant/Tool/QueryProductsTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/QueryCustomersToolTest.php`
- Test: `Test/Unit/Model/Assistant/Tool/QueryProductsToolTest.php`

**QueryCustomersTool aggregations:**
- `count_by_country`: Count customers per default billing country (join customer_address_entity)
- `count_by_group`: Count customers per group (join customer_group)
- `top_by_lifetime_value`: Top N customers by SUM(sales_order.grand_total)
- `top_by_order_count`: Top N customers by COUNT(sales_order.entity_id)

**QueryProductsTool aggregations:**
- `top_by_sales_count`: Top N products by sales_count from Typesense (or JOIN sales_order_item + GROUP BY)
- `low_stock`: Products with stock below threshold (join cataloginventory_stock_item)
- `price_range`: MIN/MAX/AVG price from catalog_product_entity_decimal
- `count_by_category`: Product count per category from catalog_category_product

**Step 1: Implement both tools following QueryOrdersTool pattern**

**Step 2: Write tests**

**Step 3: Commit**

```bash
git add Model/Assistant/Tool/QueryCustomersTool.php Model/Assistant/Tool/QueryProductsTool.php \
    Test/Unit/Model/Assistant/Tool/
git commit -m "feat(agentic): add QueryCustomersTool and QueryProductsTool"
```

---

## Task 6: Agent Loop

The core orchestrator: sends messages to OpenAI, handles tool calls, iterates until answer.

**Files:**
- Create: `Model/Assistant/AgentLoop.php`
- Test: `Test/Unit/Model/Assistant/AgentLoopTest.php`

**Step 1: Implement AgentLoop**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use OpenAI\Client as OpenAIClient;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class AgentLoop
{
    private const MAX_ITERATIONS = 5;

    public function __construct(
        private readonly OpenAIClient $openAiClient,
        private readonly ToolRegistry $toolRegistry,
        private readonly TypeSenseConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run the agent loop for a user question.
     *
     * @param string $userQuery The user's question
     * @param array<int, array<string, mixed>> $conversationHistory Previous messages for context
     * @return array{answer: string, messages: array<int, array<string, mixed>>}
     */
    public function run(string $userQuery, array $conversationHistory = []): array
    {
        $messages = $conversationHistory;

        if (empty($messages)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->config->getAdminAssistantSystemPrompt(),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $userQuery];

        $tools = $this->toolRegistry->getToolDefinitions();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->openAiClient->chat()->create([
                'model' => $this->resolveModel(),
                'messages' => $messages,
                'tools' => $tools,
            ]);

            $choice = $response->choices[0];
            $assistantMessage = $choice->message;

            // Add assistant message to history
            $messageData = ['role' => 'assistant', 'content' => $assistantMessage->content];
            if (!empty($assistantMessage->toolCalls)) {
                $messageData['tool_calls'] = array_map(fn($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->function->name,
                        'arguments' => $tc->function->arguments,
                    ],
                ], $assistantMessage->toolCalls);
            }
            $messages[] = $messageData;

            // If no tool calls, we have our final answer
            if ($choice->finishReason === 'stop' || empty($assistantMessage->toolCalls)) {
                return [
                    'answer' => $assistantMessage->content ?? '',
                    'messages' => $messages,
                ];
            }

            // Execute each tool call
            foreach ($assistantMessage->toolCalls as $toolCall) {
                $toolName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true) ?? [];

                try {
                    $tool = $this->toolRegistry->getTool($toolName);
                    $result = $tool->execute($arguments);
                } catch (\Exception $e) {
                    $this->logger->error("Agent tool error ({$toolName}): " . $e->getMessage());
                    $result = json_encode(['error' => $e->getMessage()]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => $result,
                ];
            }
        }

        // Max iterations reached
        return [
            'answer' => 'I was unable to find a complete answer within the allowed steps. Please try rephrasing your question.',
            'messages' => $messages,
        ];
    }

    private function resolveModel(): string
    {
        $model = $this->config->getAdminAssistantOpenAiModel();

        // Strip 'openai/' prefix if present (Typesense format vs OpenAI format)
        if (str_starts_with($model, 'openai/')) {
            return substr($model, 7);
        }

        return $model ?: 'gpt-4o';
    }
}
```

**Step 2: Write test** — mock OpenAI client, verify:
- Direct answer (no tool calls) returns immediately
- Tool call triggers execution and re-call
- Max iterations safety
- Tool errors are handled gracefully

**Step 3: Commit**

```bash
git add Model/Assistant/AgentLoop.php Test/Unit/Model/Assistant/
git commit -m "feat(agentic): add AgentLoop with tool execution cycle"
```

---

## Task 7: OpenAI Client Factory & DI Wiring

Create a factory for the OpenAI client and wire all tools + agent loop in di.xml.

**Files:**
- Create: `Model/Assistant/OpenAiClientFactory.php`
- Modify: `etc/di.xml`

**Step 1: Create OpenAiClientFactory**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant;

use OpenAI;
use OpenAI\Client as OpenAIClient;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class OpenAiClientFactory
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    public function create(): OpenAIClient
    {
        $apiKey = $this->config->getOpenAiApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        return OpenAI::client($apiKey);
    }
}
```

**Step 2: Wire in di.xml**

Add to `etc/di.xml`:

```xml
<!-- Agent Loop: OpenAI Client via factory -->
<type name="RunAsRoot\TypeSense\Model\Assistant\AgentLoop">
    <arguments>
        <argument name="openAiClient" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\OpenAiClientFactory</argument>
    </arguments>
</type>

<!-- Tool Registry: register all tools -->
<type name="RunAsRoot\TypeSense\Model\Assistant\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="search_typesense" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\SearchTypesenseTool</item>
            <item name="query_orders" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\QueryOrdersTool</item>
            <item name="query_customers" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\QueryCustomersTool</item>
            <item name="query_products" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\QueryProductsTool</item>
        </argument>
    </arguments>
</type>
```

Note: The `OpenAiClientFactory` needs to be injectable as a proxy. Since `AgentLoop` expects `OpenAI\Client` directly, we need a proper factory approach. The constructor of `AgentLoop` should take `OpenAiClientFactory` instead of `OpenAIClient`, and call `create()` on first use. Update `AgentLoop` accordingly:

```php
public function __construct(
    private readonly OpenAiClientFactory $openAiClientFactory,
    private readonly ToolRegistry $toolRegistry,
    private readonly TypeSenseConfigInterface $config,
    private readonly LoggerInterface $logger,
) {
}

// In run():
$client = $this->openAiClientFactory->create();
$response = $client->chat()->create([...]);
```

**Step 3: Commit**

```bash
git add Model/Assistant/OpenAiClientFactory.php etc/di.xml
git commit -m "feat(agentic): add OpenAI client factory and DI wiring for tools"
```

---

## Task 8: Rewrite Chat Controller

Replace the Typesense multi_search approach with the AgentLoop.

**Files:**
- Modify: `Controller/Adminhtml/Assistant/Chat.php`
- Modify: `view/adminhtml/web/js/assistant.js`

**Step 1: Rewrite Chat.php**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\Assistant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Model\Assistant\AgentLoop;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class Chat extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::ai_assistant';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AgentLoop $agentLoop,
        private readonly LoggerInterface $logger,
        private readonly TypeSenseConfigInterface $config,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isAdminAssistantEnabled()) {
            return $result->setData(['success' => false, 'error' => 'AI Assistant is not enabled.']);
        }

        try {
            $query = (string) $this->getRequest()->getParam('query', '');
            $historyJson = (string) $this->getRequest()->getParam('history', '');

            if (trim($query) === '') {
                return $result->setData(['success' => false, 'error' => 'Query cannot be empty.']);
            }

            $history = [];
            if ($historyJson !== '') {
                $decoded = json_decode($historyJson, true);
                if (is_array($decoded)) {
                    $history = $decoded;
                }
            }

            $response = $this->agentLoop->run($query, $history);

            return $result->setData([
                'success' => true,
                'answer' => $response['answer'],
                'messages' => $response['messages'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Admin AI Assistant error: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'error' => 'Failed to get AI response. Please try again.',
            ]);
        }
    }
}
```

**Step 2: Update assistant.js**

Change the AJAX call to:
- Send `history` (JSON-encoded messages array) instead of `conversation_id`
- On success, store `response.messages` in sessionStorage for next request
- Remove `conversationId` from state, replace with `messages` (the full OpenAI message history)

Key changes in `sendMessage`:
```javascript
$.ajax({
    url: self.chatUrl,
    method: 'POST',
    data: {
        query: query,
        history: JSON.stringify(state.openaiMessages || []),
        form_key: window.FORM_KEY
    },
    dataType: 'json',
    success: function(response) {
        if (response.success) {
            state.messages.push({ role: 'assistant', content: response.answer });
            state.openaiMessages = response.messages;  // Store full OpenAI message history
        }
        // ...
    }
});
```

And in `getState()`:
```javascript
return data ? JSON.parse(data) : { messages: [], openaiMessages: [] };
```

And "New Chat" clears both:
```javascript
saveState({ messages: [], openaiMessages: [] });
```

**Step 3: Commit**

```bash
git add Controller/Adminhtml/Assistant/Chat.php view/adminhtml/web/js/assistant.js
git commit -m "feat(agentic): rewrite Chat controller to use AgentLoop"
```

---

## Task 9: Update System Prompt & Config

Update the default system prompt for the agentic approach and remove the `isConversationalSearchEnabled` dependency.

**Files:**
- Modify: `etc/config.xml`
- Modify: `ViewModel/Adminhtml/AssistantViewModel.php`

**Step 1: Update default system prompt in config.xml**

Replace the existing `admin_assistant/system_prompt` default with the agentic version from the design doc (the one with TOOL SELECTION GUIDE).

**Step 2: Update AssistantViewModel**

Remove the `isConversationalSearchEnabled()` check — the agentic approach doesn't depend on Typesense conversation models:

```php
public function isEnabled(): bool
{
    return $this->config->isEnabled()
        && $this->config->isAdminAssistantEnabled();
}
```

**Step 3: Commit**

```bash
git add etc/config.xml ViewModel/Adminhtml/AssistantViewModel.php
git commit -m "feat(agentic): update system prompt and remove conversational search dependency"
```

---

## Task 10: Smoke Test

Verify end-to-end in Warden.

**Step 1: Deploy and compile**

```bash
cd /Users/david/Herd/mage-os-typesense
warden env exec php-fpm composer reinstall run-as-root/magento2-typesense
warden env exec php-fpm bash -c "rm -rf /var/www/html/vendor/run-as-root/magento2-typesense/vendor"
warden env exec php-fpm bin/magento setup:di:compile
warden env exec php-fpm bin/magento cache:flush
```

**Step 2: Test in browser**

Navigate to admin, click AI button, test these questions:
1. "What are my best selling products?" — should use `query_products(top_by_sales_count)`
2. "Who is my highest spending customer?" — should use `query_orders(top_customers_by_revenue)`
3. "What is my total revenue?" — should use `query_orders(total_revenue)`
4. "Which countries do my customers come from?" — should use `query_customers(count_by_country)`
5. "Search for yoga products" — should use `search_typesense(collection: product, query: yoga)`
6. "What is my store's shipping policy?" — should use `search_typesense(collection: cms_page, query: shipping policy)`

**Step 3: Verify agent tool calls in logs**

Check `/var/www/html/var/log/system.log` for tool execution entries.

**Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix(agentic): integration fixes from smoke test"
```

---

## Task Summary

| Task | Description | Depends On |
|------|-------------|------------|
| 1 | Add OpenAI PHP SDK dependency | — |
| 2 | Tool interface & registry | 1 |
| 3 | SearchTypesenseTool | 2 |
| 4 | QueryOrdersTool | 2 |
| 5 | QueryCustomersTool & QueryProductsTool | 2 |
| 6 | Agent loop | 2 |
| 7 | OpenAI client factory & DI wiring | 3, 4, 5, 6 |
| 8 | Rewrite Chat controller + JS | 6, 7 |
| 9 | Update system prompt & config | 8 |
| 10 | Smoke test | 9 |

**Parallelizable:** Tasks 3, 4, 5, 6 can all run in parallel after Task 2.
