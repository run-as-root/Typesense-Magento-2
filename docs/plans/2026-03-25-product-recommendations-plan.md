# Product Recommendations Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a "Recommended Products" slider to the PDP powered by Typesense vector similarity search.

**Architecture:** Client-side Alpine.js component queries Typesense's vector search using the current product's embedding to find semantically similar products. Results render in Hyva's native slider. Requires conversational search enabled (for embeddings). Admin can enable/disable and configure product count.

**Tech Stack:** PHP 8.3, Magento 2 (Mage-OS), Hyva Theme, Alpine.js 3, Typesense JS SDK, Tailwind CSS

---

### Task 1: Add config interface methods

**Files:**
- Modify: `Model/Config/TypeSenseConfigInterface.php`
- Modify: `Model/Config/TypeSenseConfig.php`

**Step 1: Add interface methods**

Add to `TypeSenseConfigInterface.php` before the closing `}`:

```php
public function isRecommendationsEnabled(?int $storeId = null): bool;

public function getRecommendationsLimit(?int $storeId = null): int;
```

**Step 2: Add implementation methods**

Add to `TypeSenseConfig.php` before the `private function getValue` method:

```php
// Recommendations
public function isRecommendationsEnabled(?int $storeId = null): bool
{
    return $this->isEnabled($storeId)
        && $this->isConversationalSearchEnabled($storeId)
        && $this->getFlag('recommendations/enabled', $storeId);
}

public function getRecommendationsLimit(?int $storeId = null): int
{
    return (int) ($this->getValue('recommendations/limit', $storeId) ?: 8);
}
```

**Step 3: Commit**

```bash
git add Model/Config/TypeSenseConfigInterface.php Model/Config/TypeSenseConfig.php
git commit -m "feat(recommendations): add config methods for recommendations enabled + limit"
```

---

### Task 2: Add unit tests for config methods

**Files:**
- Modify: `Test/Unit/Model/Config/TypeSenseConfigTest.php`

**Step 1: Write the failing tests**

Add these test methods to `TypeSenseConfigTest`:

```php
public function test_is_recommendations_enabled_returns_true_when_all_conditions_met(): void
{
    $this->scopeConfig->method('isSetFlag')
        ->willReturnMap([
            ['run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null, true],
            ['run_as_root_typesense/recommendations/enabled', ScopeInterface::SCOPE_STORE, null, true],
        ]);

    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/conversational_search/enabled', ScopeInterface::SCOPE_STORE, null, '1'],
        ]);

    self::assertTrue($this->sut->isRecommendationsEnabled());
}

public function test_is_recommendations_enabled_returns_false_when_conversational_search_disabled(): void
{
    $this->scopeConfig->method('isSetFlag')
        ->willReturnMap([
            ['run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null, true],
            ['run_as_root_typesense/recommendations/enabled', ScopeInterface::SCOPE_STORE, null, true],
        ]);

    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/conversational_search/enabled', ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

    self::assertFalse($this->sut->isRecommendationsEnabled());
}

public function test_get_recommendations_limit_returns_configured_value(): void
{
    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/recommendations/limit', ScopeInterface::SCOPE_STORE, null, '12'],
        ]);

    self::assertSame(12, $this->sut->getRecommendationsLimit());
}

public function test_get_recommendations_limit_returns_default_when_empty(): void
{
    $this->scopeConfig->method('getValue')
        ->willReturnMap([
            ['run_as_root_typesense/recommendations/limit', ScopeInterface::SCOPE_STORE, null, ''],
        ]);

    self::assertSame(8, $this->sut->getRecommendationsLimit());
}
```

**Step 2: Run tests to verify they pass**

Run: `composer run test`
Expected: All tests PASS (implementation already exists from Task 1).

**Step 3: Commit**

```bash
git add Test/Unit/Model/Config/TypeSenseConfigTest.php
git commit -m "test(recommendations): add unit tests for recommendations config methods"
```

---

### Task 3: Add admin config XML and defaults

**Files:**
- Modify: `etc/adminhtml/system.xml`
- Modify: `etc/config.xml`

**Step 1: Add recommendations group to system.xml**

Add after the `conversational_search` group closing `</group>` tag (before `</section>`):

```xml
<!-- Product Recommendations -->
<group id="recommendations" translate="label" type="text" sortOrder="80"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Product Recommendations</label>
    <field id="enabled" translate="label comment" type="select" sortOrder="10"
           showInDefault="1" showInWebsite="1" showInStore="1">
        <label>Enable Recommendations</label>
        <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
        <comment>Show similar products on the product detail page using vector search. Requires Conversational Search to be enabled (for product embeddings).</comment>
    </field>
    <field id="limit" translate="label comment" type="text" sortOrder="20"
           showInDefault="1" showInWebsite="1" showInStore="1">
        <label>Number of Products</label>
        <validate>validate-digits</validate>
        <comment>Maximum number of recommended products to display. Default: 8.</comment>
    </field>
</group>
```

**Step 2: Add defaults to config.xml**

Add inside `<run_as_root_typesense>`, after the `</conversational_search>` closing tag:

```xml
<recommendations>
    <enabled>0</enabled>
    <limit>8</limit>
</recommendations>
```

**Step 3: Commit**

```bash
git add etc/adminhtml/system.xml etc/config.xml
git commit -m "feat(recommendations): add admin config section with enable toggle and limit"
```

---

### Task 4: Create RecommendationsConfigViewModel

**Files:**
- Create: `ViewModel/Frontend/RecommendationsConfigViewModel.php`

**Step 1: Create the ViewModel**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\ViewModel\Frontend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class RecommendationsConfigViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isRecommendationsEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(ProductInterface $product): array
    {
        $store = $this->storeManager->getStore();
        $storeCode = $store->getCode();
        $storeId = (int) $store->getId();

        return [
            'typesenseHost'             => $this->config->getSearchHost(),
            'typesensePort'             => $this->config->getSearchPort(),
            'typesenseProtocol'         => $this->config->getSearchProtocol(),
            'typesenseSearchOnlyApiKey' => $this->config->getSearchOnlyApiKey(),
            'productCollection'         => $this->collectionNameResolver->resolve('product', $storeCode, $storeId),
            'productId'                 => (string) $product->getId(),
            'limit'                     => $this->config->getRecommendationsLimit(),
        ];
    }

    public function getJsonConfig(ProductInterface $product): string
    {
        return (string) json_encode($this->getConfig($product));
    }
}
```

**Step 2: Commit**

```bash
git add ViewModel/Frontend/RecommendationsConfigViewModel.php
git commit -m "feat(recommendations): add RecommendationsConfigViewModel for PDP"
```

---

### Task 5: Add unit tests for RecommendationsConfigViewModel

**Files:**
- Create: `Test/Unit/ViewModel/Frontend/RecommendationsConfigViewModelTest.php`

**Step 1: Write the tests**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\ViewModel\Frontend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\ViewModel\Frontend\RecommendationsConfigViewModel;

final class RecommendationsConfigViewModelTest extends TestCase
{
    private TypeSenseConfigInterface&MockObject $config;
    private StoreManagerInterface&MockObject $storeManager;
    private CollectionNameResolverInterface&MockObject $collectionNameResolver;
    private StoreInterface&MockObject $store;
    private RecommendationsConfigViewModel $sut;

    protected function setUp(): void
    {
        $this->config                 = $this->createMock(TypeSenseConfigInterface::class);
        $this->storeManager           = $this->createMock(StoreManagerInterface::class);
        $this->collectionNameResolver = $this->createMock(CollectionNameResolverInterface::class);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getCode')->willReturn('default');
        $this->store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->sut = new RecommendationsConfigViewModel(
            $this->config,
            $this->storeManager,
            $this->collectionNameResolver,
        );
    }

    public function test_is_enabled_delegates_to_config(): void
    {
        $this->config->method('isRecommendationsEnabled')->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_is_enabled_returns_false_when_config_disabled(): void
    {
        $this->config->method('isRecommendationsEnabled')->willReturn(false);

        self::assertFalse($this->sut->isEnabled());
    }

    public function test_get_config_returns_expected_keys_and_values(): void
    {
        $this->config->method('getSearchHost')->willReturn('search.example.com');
        $this->config->method('getSearchPort')->willReturn(443);
        $this->config->method('getSearchProtocol')->willReturn('https');
        $this->config->method('getSearchOnlyApiKey')->willReturn('xyz-key');
        $this->config->method('getRecommendationsLimit')->willReturn(8);

        $this->collectionNameResolver
            ->method('resolve')
            ->with('product', 'default', 1)
            ->willReturn('rar_product_default');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(42);

        $result = $this->sut->getConfig($product);

        self::assertSame('search.example.com', $result['typesenseHost']);
        self::assertSame(443, $result['typesensePort']);
        self::assertSame('https', $result['typesenseProtocol']);
        self::assertSame('xyz-key', $result['typesenseSearchOnlyApiKey']);
        self::assertSame('rar_product_default', $result['productCollection']);
        self::assertSame('42', $result['productId']);
        self::assertSame(8, $result['limit']);
    }

    public function test_get_json_config_returns_valid_json(): void
    {
        $this->config->method('getSearchHost')->willReturn('localhost');
        $this->config->method('getSearchPort')->willReturn(8108);
        $this->config->method('getSearchProtocol')->willReturn('http');
        $this->config->method('getSearchOnlyApiKey')->willReturn('key');
        $this->config->method('getRecommendationsLimit')->willReturn(6);

        $this->collectionNameResolver->method('resolve')->willReturn('test_collection');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);

        $json = $this->sut->getJsonConfig($product);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertSame('10', $decoded['productId']);
        self::assertSame(6, $decoded['limit']);
    }
}
```

**Step 2: Run tests**

Run: `composer run test`
Expected: All tests PASS.

**Step 3: Commit**

```bash
git add Test/Unit/ViewModel/Frontend/RecommendationsConfigViewModelTest.php
git commit -m "test(recommendations): add unit tests for RecommendationsConfigViewModel"
```

---

### Task 6: Create layout XML for catalog_product_view

**Files:**
- Create: `view/frontend/layout/catalog_product_view.xml`

**Step 1: Create the layout file**

```xml
<?xml version="1.0"?>
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceContainer name="content">
            <block class="Magento\Catalog\Block\Product\View"
                   name="typesense.product.recommendations"
                   template="RunAsRoot_TypeSense::product/recommendations.phtml"
                   after="-">
                <arguments>
                    <argument name="view_model" xsi:type="object">RunAsRoot\TypeSense\ViewModel\Frontend\RecommendationsConfigViewModel</argument>
                </arguments>
            </block>
        </referenceContainer>
    </body>
</page>
```

Note: We use `Magento\Catalog\Block\Product\View` (not generic Template) because it provides `$block->getProduct()` which gives us the current product on the PDP without needing the deprecated Registry.

**Step 2: Commit**

```bash
git add view/frontend/layout/catalog_product_view.xml
git commit -m "feat(recommendations): add PDP layout XML for recommendations block"
```

---

### Task 7: Create the recommendations template

**Files:**
- Create: `view/frontend/templates/product/recommendations.phtml`

**Step 1: Create the template**

This template follows the same patterns as `search/results.phtml`:
- CSP-safe Alpine.js with `Alpine.data()` registration
- DOM rendering via innerHTML (not Alpine expressions)
- Typesense JS SDK loaded from CDN
- Hyva slider component for the carousel

```php
<?php
/**
 * Typesense Product Recommendations — Hyva CSP-compatible
 *
 * Vector similarity search for "similar products" on PDP.
 * Uses JS DOM rendering for CSP compliance.
 */

declare(strict_types=1);

use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Catalog\Block\Product\View as ProductViewBlock;
use Magento\Framework\Escaper;
use RunAsRoot\TypeSense\ViewModel\Frontend\RecommendationsConfigViewModel;

/** @var Escaper $escaper */
/** @var ProductViewBlock $block */
/** @var ViewModelRegistry $viewModels */

/** @var RecommendationsConfigViewModel $viewModel */
$viewModel = $block->getData('view_model');
if (!$viewModel->isEnabled()) {
    return;
}

$product = $block->getProduct();
if (!$product) {
    return;
}

/** @var HyvaCsp $hyvaCsp */
$hyvaCsp = $viewModels->require(HyvaCsp::class);

$config = $viewModel->getJsonConfig($product);
?>

<script src="https://cdn.jsdelivr.net/npm/typesense@1.8.2/dist/typesense.min.js"></script>
<script>
    function initTypesenseRecommendations() {
        const config = <?= /* @noEscape */ $config ?>;

        return {
            recommendations: [],
            loading: true,
            error: false,
            client: null,
            currentIndex: 0,

            init() {
                this.client = new Typesense.Client({
                    nodes: [{ host: config.typesenseHost, port: String(config.typesensePort), protocol: config.typesenseProtocol }],
                    apiKey: config.typesenseSearchOnlyApiKey,
                    connectionTimeoutSeconds: 2,
                });

                this.fetchRecommendations();
            },

            async fetchRecommendations() {
                try {
                    const result = await this.client.collections(config.productCollection).documents().search({
                        q: '*',
                        vector_query: 'embedding:([], id:' + config.productId + ')',
                        filter_by: 'id:!=' + config.productId + ' && in_stock:true',
                        per_page: config.limit,
                        exclude_fields: 'embedding',
                    });

                    this.recommendations = (result.hits || []).map(h => h.document);
                } catch (e) {
                    console.warn('Typesense recommendations failed:', e);
                    this.error = true;
                }

                this.loading = false;
                this.$nextTick(() => this.renderProducts());
            },

            esc(str) {
                const div = document.createElement('div');
                div.textContent = String(str || '');
                return div.innerHTML;
            },

            slideLeft() {
                const container = this.$el.querySelector('[data-rec-slider]');
                if (container) container.scrollBy({ left: -280, behavior: 'smooth' });
            },

            slideRight() {
                const container = this.$el.querySelector('[data-rec-slider]');
                if (container) container.scrollBy({ left: 280, behavior: 'smooth' });
            },

            renderProducts() {
                const container = this.$el.querySelector('[data-rec-slider]');
                const wrapper = this.$el.querySelector('[data-rec-wrapper]');
                if (!container || !wrapper) return;

                if (this.recommendations.length === 0) {
                    wrapper.style.display = 'none';
                    return;
                }

                let html = '';
                for (const doc of this.recommendations) {
                    const price = doc.price ? '$' + Number(doc.price).toFixed(2) : '';
                    const specialPrice = doc.special_price ? '$' + Number(doc.special_price).toFixed(2) : '';
                    const hasDiscount = specialPrice && doc.special_price < doc.price;

                    const imgHtml = doc.image_url
                        ? '<img src="' + this.esc(doc.image_url) + '" alt="' + this.esc(doc.name) + '" class="w-full h-full object-contain" loading="lazy"/>'
                        : '<div class="w-full h-full bg-gray-100 flex items-center justify-center"><span class="text-gray-300 text-xs">No image</span></div>';

                    let priceHtml = '';
                    if (hasDiscount) {
                        priceHtml = '<span class="text-sm font-bold text-red-600">' + this.esc(specialPrice) + '</span>'
                            + ' <span class="text-xs text-gray-400 line-through">' + this.esc(price) + '</span>';
                    } else {
                        priceHtml = '<span class="text-sm font-bold text-gray-800">' + this.esc(price) + '</span>';
                    }

                    html += '<div class="flex-shrink-0 w-56 snap-start">'
                        + '<a href="' + this.esc(doc.url) + '" class="block border border-gray-200 rounded-lg p-3 hover:shadow-md transition-shadow h-full flex flex-col">'
                        + '<div class="aspect-square mb-3 overflow-hidden rounded">' + imgHtml + '</div>'
                        + '<h3 class="text-sm font-medium text-gray-800 mb-1 line-clamp-2">' + this.esc(doc.name) + '</h3>'
                        + '<div class="mt-auto pt-2">' + priceHtml + '</div>'
                        + '</a></div>';
                }

                container.innerHTML = html;

                // Bind arrow buttons
                const leftBtn = this.$el.querySelector('[data-rec-left]');
                const rightBtn = this.$el.querySelector('[data-rec-right]');
                if (leftBtn) leftBtn.addEventListener('click', () => this.slideLeft());
                if (rightBtn) rightBtn.addEventListener('click', () => this.slideRight());
            },
        };
    }

    window.addEventListener('alpine:init', () => {
        Alpine.data('initTypesenseRecommendations', initTypesenseRecommendations);
    }, { once: true });
</script>
<?php $hyvaCsp->registerInlineScript() ?>

<div x-data="initTypesenseRecommendations" class="mt-12 mb-8" data-rec-wrapper>
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold text-gray-800">
            <?= $escaper->escapeHtml(__('You May Also Like')) ?>
        </h2>
        <div class="flex gap-2">
            <button data-rec-left
                    class="p-2 rounded-full border border-gray-300 hover:bg-gray-100 transition-colors cursor-pointer"
                    aria-label="<?= $escaper->escapeHtmlAttr(__('Previous')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <button data-rec-right
                    class="p-2 rounded-full border border-gray-300 hover:bg-gray-100 transition-colors cursor-pointer"
                    aria-label="<?= $escaper->escapeHtmlAttr(__('Next')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>
    </div>

    <div data-rec-slider
         class="flex gap-4 overflow-x-auto snap-x snap-mandatory scroll-smooth pb-4"
         style="-webkit-overflow-scrolling: touch; scrollbar-width: thin;">
        <div class="text-center py-8 w-full">
            <p class="text-gray-400 text-sm"><?= $escaper->escapeHtml(__('Loading recommendations...')) ?></p>
        </div>
    </div>
</div>
```

Note: We use a simple horizontal scroll with snap points and arrow buttons instead of Hyva's slider component. This is more lightweight, doesn't require extra Hyva slider dependencies, and follows the same CSP-safe DOM rendering pattern as the rest of the extension. The scroll-snap CSS gives a native slider feel.

**Step 2: Commit**

```bash
git add view/frontend/templates/product/recommendations.phtml
git commit -m "feat(recommendations): add PDP template with vector search slider"
```

---

### Task 8: Run full test suite and verify

**Step 1: Run all unit tests**

Run: `composer run test`
Expected: All tests PASS, including the new config and ViewModel tests.

**Step 2: Verify no PHPCS issues**

Run: `vendor/bin/phpcs --standard=.phpcs.xml Model/Config/TypeSenseConfig.php Model/Config/TypeSenseConfigInterface.php ViewModel/Frontend/RecommendationsConfigViewModel.php`
Expected: No errors.

**Step 3: Verify PHPStan passes**

Run: `vendor/bin/phpstan analyse --configuration=phpstan.neon`
Expected: No new errors.

**Step 4: Final commit if any fixes were needed**

```bash
git add -A
git commit -m "fix(recommendations): address linting/static analysis issues"
```

---

### Task 9: Manual testing in Warden

**Prerequisites:**
- Conversational search must be enabled with a valid OpenAI key
- Products must be reindexed to generate embeddings

**Step 1: Enable recommendations in admin**

Navigate to: Stores > Configuration > TypeSense > Product Recommendations
- Set "Enable Recommendations" to Yes
- Set "Number of Products" to 8

**Step 2: Flush caches**

```bash
warden env exec php-fpm bin/magento cache:flush
```

**Step 3: Visit a product detail page**

Navigate to any product page on the frontend. Verify:
- [ ] "You May Also Like" section appears below product info
- [ ] Slider shows recommended products
- [ ] Products are semantically related (not random)
- [ ] Left/right arrow navigation works
- [ ] Product cards link to correct product pages
- [ ] Images, names, and prices display correctly
- [ ] Special prices show with strikethrough on original price
- [ ] Section is hidden when recommendations feature is disabled
- [ ] No console errors

**Step 4: Verify fallback behavior**

Disable conversational search in admin, flush cache, reload PDP:
- [ ] Recommendations section does not appear (graceful hide)

---

## Summary of all files

**New files (3):**
- `ViewModel/Frontend/RecommendationsConfigViewModel.php`
- `Test/Unit/ViewModel/Frontend/RecommendationsConfigViewModelTest.php`
- `view/frontend/layout/catalog_product_view.xml`
- `view/frontend/templates/product/recommendations.phtml`

**Modified files (4):**
- `Model/Config/TypeSenseConfigInterface.php` (2 new methods)
- `Model/Config/TypeSenseConfig.php` (2 new methods)
- `Test/Unit/Model/Config/TypeSenseConfigTest.php` (4 new tests)
- `etc/adminhtml/system.xml` (new recommendations group)
- `etc/config.xml` (new defaults)
