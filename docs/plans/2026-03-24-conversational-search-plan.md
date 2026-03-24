# Conversational Search (RAG) Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add natural language conversational search powered by Typesense RAG — customers ask questions, get AI answers above the product grid.

**Architecture:** Upgrade typesense-php to v6, add auto-embedding vector field to product schema, configure OpenAI as LLM provider via admin, create ConversationModelManager to sync Typesense conversation models, and enhance the instant search frontend to show AI answers from multi_search with `conversation=true`.

**Tech Stack:** Typesense v30+ with built-in RAG, typesense-php v6.0, OpenAI API, Alpine.js (Hyva CSP-safe)

---

### Task 1: Upgrade typesense-php SDK to v6

**Files:**
- Modify: `composer.json`

**Step 1: Update the version constraint**

Change `typesense/typesense-php` from `^4.9` to `^6.0` in `require`:

```json
"require": {
    "php": "^8.3",
    "typesense/typesense-php": "^6.0"
}
```

**Step 2: Validate JSON**

Run: `python3 -c "import json; json.load(open('composer.json'))"`
Expected: No output (valid JSON)

**Step 3: Commit**

```bash
git add composer.json
git commit -m "build: upgrade typesense-php SDK from v4.9 to v6.0 for RAG support"
```

---

### Task 2: Add conversational search config to admin

**Files:**
- Modify: `etc/adminhtml/system.xml`
- Modify: `etc/config.xml`

**Step 1: Add the conversational_search group to system.xml**

After the `merchandising` group (sortOrder 60), add a new group with sortOrder 70. Insert before the closing `</section>` tag:

```xml
<group id="conversational_search" translate="label" type="text" sortOrder="70"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Conversational Search (AI)</label>
    <field id="enabled" translate="label comment" type="select" sortOrder="10"
           showInDefault="1" showInWebsite="1" showInStore="1">
        <label>Enable Conversational Search</label>
        <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
        <comment>When enabled, search results include an AI-generated answer above the product grid. Requires OpenAI API key and Typesense v30+.</comment>
    </field>
    <field id="openai_api_key" translate="label comment" type="obscure" sortOrder="20"
           showInDefault="1" showInWebsite="1" showInStore="1">
        <label>OpenAI API Key</label>
        <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
        <comment>API key for generating conversational answers. Get one at platform.openai.com.</comment>
    </field>
    <field id="openai_model" translate="label comment" type="select" sortOrder="30"
           showInDefault="1" showInWebsite="1" showInStore="1">
        <label>OpenAI Model</label>
        <source_model>RunAsRoot\TypeSense\Model\Config\Source\OpenAiModelSource</source_model>
        <comment>The model used for generating answers. gpt-4o-mini is cheapest and fastest.</comment>
    </field>
    <field id="system_prompt" translate="label comment" type="textarea" sortOrder="40"
           showInDefault="1" showInWebsite="1" showInStore="1">
        <label>System Prompt</label>
        <comment>Instructions for the AI assistant. Controls tone, scope, and behavior.</comment>
    </field>
    <field id="embedding_fields" translate="label comment" type="multiselect" sortOrder="50"
           showInDefault="1" showInWebsite="0" showInStore="0">
        <label>Embedding Source Fields</label>
        <source_model>RunAsRoot\TypeSense\Model\Config\Source\EmbeddingFieldSource</source_model>
        <comment>Product fields used to generate semantic embeddings. Changes require a full product reindex.</comment>
    </field>
    <field id="conversation_ttl" translate="label comment" type="text" sortOrder="60"
           showInDefault="1" showInWebsite="0" showInStore="0">
        <label>Conversation TTL (seconds)</label>
        <comment>How long conversation history is kept. Default: 86400 (24 hours).</comment>
    </field>
</group>
```

**Step 2: Add default values to config.xml**

In `config.xml`, inside the `<run_as_root_typesense>` node, add after `<merchandising>`:

```xml
<conversational_search>
    <enabled>0</enabled>
    <openai_api_key/>
    <openai_model>openai/gpt-4o-mini</openai_model>
    <system_prompt>You are a helpful shopping assistant for an online store. Answer questions about products based only on the provided context. Include specific product names and prices when relevant. If you cannot answer from the context, say so politely.</system_prompt>
    <embedding_fields>name,description</embedding_fields>
    <conversation_ttl>86400</conversation_ttl>
</conversational_search>
```

**Step 3: Commit**

```bash
git add etc/adminhtml/system.xml etc/config.xml
git commit -m "feat: add conversational search admin configuration"
```

---

### Task 3: Create OpenAiModelSource and EmbeddingFieldSource

**Files:**
- Create: `Model/Config/Source/OpenAiModelSource.php`
- Create: `Model/Config/Source/EmbeddingFieldSource.php`
- Test: `Test/Unit/Model/Config/Source/OpenAiModelSourceTest.php`
- Test: `Test/Unit/Model/Config/Source/EmbeddingFieldSourceTest.php`

**Step 1: Write the OpenAiModelSource test**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\OpenAiModelSource;

final class OpenAiModelSourceTest extends TestCase
{
    public function test_to_option_array_returns_openai_models(): void
    {
        $sut = new OpenAiModelSource();
        $options = $sut->toOptionArray();

        self::assertNotEmpty($options);
        self::assertSame('openai/gpt-4o-mini', $options[0]['value']);
    }
}
```

**Step 2: Write the OpenAiModelSource implementation**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OpenAiModelSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openai/gpt-4o-mini', 'label' => 'GPT-4o Mini (fastest, cheapest)'],
            ['value' => 'openai/gpt-4o', 'label' => 'GPT-4o (balanced)'],
            ['value' => 'openai/gpt-4-turbo', 'label' => 'GPT-4 Turbo (most capable)'],
        ];
    }
}
```

**Step 3: Write the EmbeddingFieldSource**

This source model provides a list of product fields that can be embedded. It offers the core text fields plus any additional attributes configured in the indexing section.

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EmbeddingFieldSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'name', 'label' => 'Product Name'],
            ['value' => 'description', 'label' => 'Description'],
            ['value' => 'short_description', 'label' => 'Short Description'],
            ['value' => 'sku', 'label' => 'SKU'],
            ['value' => 'categories_text', 'label' => 'Category Names (text)'],
        ];
    }
}
```

**Step 4: Write EmbeddingFieldSource test**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\Source\EmbeddingFieldSource;

final class EmbeddingFieldSourceTest extends TestCase
{
    public function test_to_option_array_contains_name_and_description(): void
    {
        $sut = new EmbeddingFieldSource();
        $options = $sut->toOptionArray();
        $values = array_column($options, 'value');

        self::assertContains('name', $values);
        self::assertContains('description', $values);
    }
}
```

**Step 5: Run tests**

Run: `composer run test`
Expected: PASS

**Step 6: Commit**

```bash
git add Model/Config/Source/OpenAiModelSource.php Model/Config/Source/EmbeddingFieldSource.php Test/Unit/Model/Config/Source/
git commit -m "feat: add OpenAI model and embedding field source models"
```

---

### Task 4: Add conversational search getters to config

**Files:**
- Modify: `Model/Config/TypeSenseConfigInterface.php`
- Modify: `Model/Config/TypeSenseConfig.php`
- Test: `Test/Unit/Model/Config/TypeSenseConfigTest.php`

**Step 1: Add method signatures to the interface**

Append before the closing `}`:

```php
public function isConversationalSearchEnabled(?int $storeId = null): bool;
public function getOpenAiApiKey(?int $storeId = null): string;
public function getOpenAiModel(?int $storeId = null): string;
public function getConversationalSystemPrompt(?int $storeId = null): string;
public function getEmbeddingFields(?int $storeId = null): array;
public function getConversationTtl(?int $storeId = null): int;
```

**Step 2: Implement in TypeSenseConfig**

Add these methods. Follow the existing pattern — use `$this->getValue()` with the path prefix. The OpenAI API key should be decrypted like the admin API key.

```php
public function isConversationalSearchEnabled(?int $storeId = null): bool
{
    return $this->isEnabled($storeId)
        && (bool) $this->getValue('conversational_search/enabled', $storeId);
}

public function getOpenAiApiKey(?int $storeId = null): string
{
    $encrypted = (string) $this->getValue('conversational_search/openai_api_key', $storeId);
    return $this->encryptor->decrypt($encrypted);
}

public function getOpenAiModel(?int $storeId = null): string
{
    return (string) ($this->getValue('conversational_search/openai_model', $storeId) ?: 'openai/gpt-4o-mini');
}

public function getConversationalSystemPrompt(?int $storeId = null): string
{
    return (string) $this->getValue('conversational_search/system_prompt', $storeId);
}

public function getEmbeddingFields(?int $storeId = null): array
{
    $value = (string) $this->getValue('conversational_search/embedding_fields', $storeId);
    return $value !== '' ? explode(',', $value) : ['name', 'description'];
}

public function getConversationTtl(?int $storeId = null): int
{
    return (int) ($this->getValue('conversational_search/conversation_ttl', $storeId) ?: 86400);
}
```

**Step 3: Add tests**

Add test methods to the existing `TypeSenseConfigTest.php`:

```php
public function test_is_conversational_search_enabled_returns_false_when_module_disabled(): void
{
    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

    self::assertFalse($this->sut->isConversationalSearchEnabled());
}

public function test_get_embedding_fields_returns_array(): void
{
    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/conversational_search/embedding_fields', ScopeInterface::SCOPE_STORE, null, 'name,description,sku'],
        ]);

    self::assertSame(['name', 'description', 'sku'], $this->sut->getEmbeddingFields());
}

public function test_get_embedding_fields_returns_defaults_when_empty(): void
{
    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/conversational_search/embedding_fields', ScopeInterface::SCOPE_STORE, null, ''],
        ]);

    self::assertSame(['name', 'description'], $this->sut->getEmbeddingFields());
}
```

**Step 4: Run tests**

Run: `composer run test`
Expected: PASS

**Step 5: Commit**

```bash
git add Model/Config/TypeSenseConfigInterface.php Model/Config/TypeSenseConfig.php Test/Unit/Model/Config/TypeSenseConfigTest.php
git commit -m "feat: add conversational search config getters"
```

---

### Task 5: Add embedding vector field to product schema

**Files:**
- Modify: `Model/Indexer/Product/ProductSchemaProvider.php`
- Test: `Test/Unit/Model/Indexer/Product/ProductSchemaProviderTest.php`

**Step 1: Add a `categories_text` field and the embedding vector field**

In `getFields()`, after the existing dynamic attribute loop, add:

```php
// Text representation of categories for embedding
$fields[] = ['name' => 'categories_text', 'type' => 'string', 'optional' => true];

// Auto-embedding for conversational/semantic search
if ($this->config->isConversationalSearchEnabled()) {
    $fields[] = [
        'name' => 'embedding',
        'type' => 'float[]',
        'embed' => [
            'from' => $this->config->getEmbeddingFields(),
            'model_config' => [
                'model_name' => 'ts/all-MiniLM-L12-v2',
            ],
        ],
    ];
}
```

**Step 2: Update ProductDataBuilder to populate `categories_text`**

In `Model/Indexer/Product/ProductDataBuilder.php`, in the document building method, add a string version of category names:

```php
$doc['categories_text'] = implode(', ', $categoryNames);
```

Where `$categoryNames` is already resolved for the `categories` field.

**Step 3: Add test**

In `ProductSchemaProviderTest.php`, add:

```php
public function test_get_fields_includes_embedding_when_conversational_enabled(): void
{
    $this->config->method('isConversationalSearchEnabled')->willReturn(true);
    $this->config->method('getEmbeddingFields')->willReturn(['name', 'description']);
    $this->config->method('getAdditionalAttributes')->willReturn([]);

    $fields = $this->sut->getFields();
    $fieldNames = array_column($fields, 'name');

    self::assertContains('embedding', $fieldNames);

    $embeddingField = array_values(array_filter($fields, fn($f) => $f['name'] === 'embedding'))[0];
    self::assertSame('float[]', $embeddingField['type']);
    self::assertSame(['name', 'description'], $embeddingField['embed']['from']);
}

public function test_get_fields_excludes_embedding_when_conversational_disabled(): void
{
    $this->config->method('isConversationalSearchEnabled')->willReturn(false);
    $this->config->method('getAdditionalAttributes')->willReturn([]);

    $fields = $this->sut->getFields();
    $fieldNames = array_column($fields, 'name');

    self::assertNotContains('embedding', $fieldNames);
}
```

**Step 4: Run tests**

Run: `composer run test`
Expected: PASS

**Step 5: Commit**

```bash
git add Model/Indexer/Product/ProductSchemaProvider.php Model/Indexer/Product/ProductDataBuilder.php Test/Unit/Model/Indexer/Product/ProductSchemaProviderTest.php
git commit -m "feat: add auto-embedding vector field to product schema"
```

---

### Task 6: Create ConversationModelManager

**Files:**
- Create: `Model/Conversation/ConversationModelManager.php`
- Test: `Test/Unit/Model/Conversation/ConversationModelManagerTest.php`

**Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Conversation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;

final class ConversationModelManagerTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private TypeSenseClientFactoryInterface&MockObject $clientFactory;
    private ConversationModelManager $sut;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $this->sut = new ConversationModelManager($this->config, $this->clientFactory);
    }

    public function test_get_model_config_returns_expected_structure(): void
    {
        $this->config->method('getOpenAiApiKey')->willReturn('sk-test-key');
        $this->config->method('getOpenAiModel')->willReturn('openai/gpt-4o-mini');
        $this->config->method('getConversationalSystemPrompt')->willReturn('You are helpful.');
        $this->config->method('getConversationTtl')->willReturn(86400);
        $this->config->method('getIndexPrefix')->willReturn('rar');

        $modelConfig = $this->sut->getModelConfig();

        self::assertSame('openai/gpt-4o-mini', $modelConfig['model_name']);
        self::assertSame('sk-test-key', $modelConfig['api_key']);
        self::assertSame('rar_conversation_history', $modelConfig['history_collection']);
        self::assertSame('You are helpful.', $modelConfig['system_prompt']);
        self::assertSame(86400, $modelConfig['ttl']);
    }
}
```

**Step 2: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Conversation;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class ConversationModelManager
{
    private const CONVERSATION_MODEL_ID = 'rar-product-assistant';

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

    public function getModelConfig(?int $storeId = null): array
    {
        return [
            'id' => self::CONVERSATION_MODEL_ID,
            'model_name' => $this->config->getOpenAiModel($storeId),
            'api_key' => $this->config->getOpenAiApiKey($storeId),
            'history_collection' => $this->config->getIndexPrefix($storeId) . '_conversation_history',
            'system_prompt' => $this->config->getConversationalSystemPrompt($storeId),
            'max_bytes' => 16384,
            'ttl' => $this->config->getConversationTtl($storeId),
        ];
    }

    public function sync(?int $storeId = null): void
    {
        if (!$this->config->isConversationalSearchEnabled($storeId)) {
            $this->delete($storeId);
            return;
        }

        try {
            $client = $this->clientFactory->create($storeId);
            $modelConfig = $this->getModelConfig($storeId);

            try {
                $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->update($modelConfig);
            } catch (\Exception $e) {
                $client->conversations->getModels()->create($modelConfig);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to sync conversation model: ' . $e->getMessage());
        }
    }

    public function delete(?int $storeId = null): void
    {
        try {
            $client = $this->clientFactory->create($storeId);
            $client->conversations->getModels()[self::CONVERSATION_MODEL_ID]->delete();
        } catch (\Exception $e) {
            // Model may not exist, ignore
        }
    }
}
```

**Step 3: Register in di.xml** — no registration needed, constructor injection handles it.

**Step 4: Run tests**

Run: `composer run test`
Expected: PASS (the test only validates getModelConfig, not the sync which needs a real client)

**Step 5: Commit**

```bash
git add Model/Conversation/ConversationModelManager.php Test/Unit/Model/Conversation/ConversationModelManagerTest.php
git commit -m "feat: add ConversationModelManager for Typesense RAG model lifecycle"
```

---

### Task 7: Pass conversational config to frontend

**Files:**
- Modify: `ViewModel/Frontend/InstantSearchConfigViewModel.php`
- Test: `Test/Unit/ViewModel/Frontend/InstantSearchConfigViewModelTest.php`

**Step 1: Add conversation config to getConfig()**

In the return array of `getConfig()`, add:

```php
'conversationalSearch' => [
    'enabled' => $this->config->isConversationalSearchEnabled(),
    'modelId' => $this->conversationModelManager->getModelId(),
],
```

Inject `ConversationModelManager` in the constructor.

**Step 2: Add test**

```php
public function test_get_config_includes_conversational_search_when_enabled(): void
{
    // ... setup mocks for existing getConfig requirements ...
    $this->config->method('isConversationalSearchEnabled')->willReturn(true);

    $result = $this->sut->getConfig();

    self::assertArrayHasKey('conversationalSearch', $result);
    self::assertTrue($result['conversationalSearch']['enabled']);
}
```

**Step 3: Run tests, commit**

```bash
git add ViewModel/Frontend/InstantSearchConfigViewModel.php Test/Unit/ViewModel/Frontend/InstantSearchConfigViewModelTest.php
git commit -m "feat: pass conversational search config to frontend"
```

---

### Task 8: Enhance instant search JS for conversational queries

**Files:**
- Modify: `view/frontend/web/js/instant-search.js`
- Modify: `view/frontend/templates/search/results.phtml`

**Step 1: Add AI answer state and conversation tracking**

In the `initTypesenseInstantSearch()` state, add:

```javascript
aiAnswer: '',
conversationId: null,
isConversational: config.conversationalSearch?.enabled || false,
conversationModelId: config.conversationalSearch?.modelId || '',
```

**Step 2: Modify the search method**

When `isConversational` is true, use `multi_search` instead of regular search:

```javascript
if (this.isConversational && this.query.length > 5) {
    // Use conversational multi_search for longer queries
    const searchParams = {
        searches: [{
            collection: config.productCollection,
            query_by: 'embedding',
            exclude_fields: 'embedding',
            per_page: config.productsPerPage,
            filter_by: filterStr || undefined,
        }]
    };
    const commonParams = {
        q: this.query,
        conversation: true,
        conversation_model_id: this.conversationModelId,
    };
    if (this.conversationId) {
        commonParams.conversation_id = this.conversationId;
    }

    const response = await this.client.multiSearch.perform(searchParams, commonParams);

    if (response.conversation) {
        this.aiAnswer = response.conversation.answer || '';
        this.conversationId = response.conversation.conversation_id || null;
    }

    // Process hits from results[0]
    const result = response.results[0];
    this.hits = result.hits || [];
    this.stats = { found: result.found, time: result.search_time_ms };
    this.totalPages = Math.ceil(result.found / config.productsPerPage);
} else {
    // Existing keyword search path
    this.aiAnswer = '';
    // ... existing search code ...
}
```

**Step 3: Add AI answer box to results template**

In `results.phtml`, after the stats/sort bar and before the product grid, add:

```javascript
// In renderProducts() or a new renderAiAnswer() method:
renderAiAnswer() {
    const container = document.getElementById('typesense-ai-answer');
    if (!container) return;
    if (!this.aiAnswer) {
        container.style.display = 'none';
        return;
    }
    container.style.display = 'block';
    container.innerHTML = '<div class="ts-ai-answer">' +
        '<div class="ts-ai-answer-label">AI Shopping Assistant</div>' +
        '<div class="ts-ai-answer-text">' + this.esc(this.aiAnswer) + '</div>' +
        '</div>';
}
```

Add the container div in the template HTML:

```html
<div id="typesense-ai-answer" style="display:none;"></div>
```

**Step 4: Add CSS for the AI answer box**

In `view/frontend/web/css/instant-search.css`, add:

```css
.ts-ai-answer {
    background: #f0f7ff;
    border: 1px solid #bdd7f1;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
}
.ts-ai-answer-label {
    font-weight: 600;
    font-size: 13px;
    color: #1a56db;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.ts-ai-answer-text {
    font-size: 15px;
    line-height: 1.6;
    color: #1f2937;
}
```

**Step 5: Commit**

```bash
git add view/frontend/web/js/instant-search.js view/frontend/templates/search/results.phtml view/frontend/web/css/instant-search.css
git commit -m "feat: add AI answer box to instant search with conversational multi_search"
```

---

### Task 9: Sync conversation model on config save

**Files:**
- Create: `Observer/ConversationConfigSave.php`
- Modify: `etc/events.xml` or `etc/adminhtml/events.xml`

**Step 1: Create the observer**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;

class ConversationConfigSave implements ObserverInterface
{
    public function __construct(
        private readonly ConversationModelManager $conversationModelManager,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->conversationModelManager->sync();
    }
}
```

**Step 2: Register in adminhtml events.xml**

Create or modify `etc/adminhtml/events.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="admin_system_config_changed_section_run_as_root_typesense">
        <observer name="typesense_conversation_config_save"
                  instance="RunAsRoot\TypeSense\Observer\ConversationConfigSave"/>
    </event>
</config>
```

**Step 3: Commit**

```bash
git add Observer/ConversationConfigSave.php etc/adminhtml/events.xml
git commit -m "feat: sync conversation model to Typesense on config save"
```

---

### Task 10: Update Warden Typesense to v30 and smoke test

**Step 1: Update Warden environment config**

Update the Typesense service version in the Warden `.env` or `docker-compose` to use Typesense v30+.

**Step 2: Run reindex**

```bash
cd /Users/david/Herd/mage-os-typesense
warden env exec php-fpm bash -c "cd /var/www/html && bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush && bin/magento typesense:reindex 2>&1"
```

**Step 3: Configure in admin**

1. Go to Stores > Configuration > TypeSense > Conversational Search
2. Enable Conversational Search: Yes
3. Enter OpenAI API key
4. Select model: gpt-4o-mini
5. Select embedding fields: name, description
6. Save Config (triggers observer → syncs conversation model)

**Step 4: Test on frontend**

1. Go to the storefront search page
2. Search for "what's a good jacket for rainy weather?"
3. Verify AI answer appears above product grid
4. Verify product results still show below
5. Ask a follow-up question

**Step 5: Final commit**

```bash
git add -A
git commit -m "feat: conversational search (RAG) complete"
```
