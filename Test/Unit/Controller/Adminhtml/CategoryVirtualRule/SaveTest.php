<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule\Save;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Curation\CategoryMerchandisingSync;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRule;
use RunAsRoot\TypeSense\Model\VirtualRule\CategoryVirtualRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Combine;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRule;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product;
use RunAsRoot\TypeSense\Model\VirtualRule\VirtualRuleCacheInvalidator;

final class SaveTest extends TestCase
{
    private Context&MockObject $context;
    private RequestInterface&MockObject $request;
    private JsonFactory&MockObject $jsonFactory;
    private Json&MockObject $resultJson;
    private CategoryVirtualRuleRepositoryInterface&MockObject $ruleRepository;
    private CategoryVirtualRuleFactory&MockObject $ruleFactory;
    private ConditionsRuleFactory&MockObject $conditionsRuleFactory;
    private VirtualRuleCacheInvalidator&MockObject $cacheInvalidator;
    private CategoryMerchandisingSync&MockObject $merchandisingSync;
    private StoreManagerInterface&MockObject $storeManager;
    private TypeSenseConfigInterface&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private Save $sut;

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed>|null Captured argument of the last resultJson->setData() call. */
    private ?array $jsonPayload = null;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => $this->params[$key] ?? $default,
        );

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);

        $this->resultJson = $this->createMock(Json::class);
        $this->resultJson->method('setData')->willReturnCallback(function (array $data) {
            $this->jsonPayload = $data;

            return $this->resultJson;
        });

        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->jsonFactory->method('create')->willReturn($this->resultJson);

        $this->ruleRepository = $this->createMock(CategoryVirtualRuleRepositoryInterface::class);
        $this->ruleFactory = $this->createMock(CategoryVirtualRuleFactory::class);
        $this->conditionsRuleFactory = $this->createMock(ConditionsRuleFactory::class);
        $this->cacheInvalidator = $this->createMock(VirtualRuleCacheInvalidator::class);
        $this->merchandisingSync = $this->createMock(CategoryMerchandisingSync::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sut = new Save(
            $this->context,
            $this->jsonFactory,
            $this->ruleRepository,
            $this->ruleFactory,
            $this->conditionsRuleFactory,
            $this->cacheInvalidator,
            $this->merchandisingSync,
            $this->storeManager,
            $this->config,
            $this->logger,
        );
    }

    public function test_save_persists_rule_with_posted_fields_and_returns_success(): void
    {
        $this->params = [
            'category_id' => 5,
            'store_id' => 1,
            'is_virtual' => 1,
            'virtual_category_root_id' => '10',
            'rule' => ['conditions' => $this->flatConditionsPost()],
        ];

        $conditionsArray = $this->conditionsTree(['sku', 'color']);
        $this->stubParsedConditions($conditionsArray);

        $this->config->method('getAdditionalAttributes')->with(1)->willReturn(['color']);

        $rule = $this->createMock(CategoryVirtualRule::class);
        $this->ruleRepository->method('findByCategoryAndStore')->with(5, 1)->willReturn(null);
        $this->ruleFactory->method('create')->willReturn($rule);

        $rule->expects(self::once())->method('setCategoryId')->with(5)->willReturnSelf();
        $rule->expects(self::once())->method('setStoreId')->with(1)->willReturnSelf();
        $rule->expects(self::once())->method('setIsVirtual')->with(true)->willReturnSelf();
        $rule->expects(self::once())
            ->method('setConditionsSerialized')
            ->with(json_encode($conditionsArray, JSON_THROW_ON_ERROR))
            ->willReturnSelf();
        $rule->expects(self::once())->method('setVirtualCategoryRootId')->with(10)->willReturnSelf();
        $rule->expects(self::once())->method('setCompiledFilterCache')->with(null)->willReturnSelf();

        $this->ruleRepository->expects(self::once())->method('save')->with($rule);

        $this->cacheInvalidator->method('invalidate')->with(5, 1)->willReturn([]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $this->storeManager->method('getStore')->with(1)->willReturn($store);

        $this->merchandisingSync->expects(self::once())->method('sync')->with(5, 1, 'default');

        $this->sut->execute();

        self::assertSame(['success' => true], $this->jsonPayload);
    }

    public function test_save_syncs_edited_category_and_every_cleared_ancestor(): void
    {
        $this->params = [
            'category_id' => 7,
            'store_id' => 1,
            'rule' => ['conditions' => $this->flatConditionsPost()],
        ];

        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $rule = $this->createMock(CategoryVirtualRule::class);
        $rule->method('setCategoryId')->willReturnSelf();
        $rule->method('setStoreId')->willReturnSelf();
        $rule->method('setIsVirtual')->willReturnSelf();
        $rule->method('setConditionsSerialized')->willReturnSelf();
        $rule->method('setVirtualCategoryRootId')->willReturnSelf();
        $rule->method('setCompiledFilterCache')->willReturnSelf();
        $this->ruleRepository->method('findByCategoryAndStore')->willReturn($rule);

        // invalidate() reports two ancestors as cleared; the edited category (7) itself never
        // appears here since Save already nulled its own cache before calling invalidate() — see
        // Save::execute()'s docblock. Both must still be synced.
        $this->cacheInvalidator->expects(self::once())->method('invalidate')->with(7, 1)->willReturn([9, 12]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $this->storeManager->method('getStore')->with(1)->willReturn($store);

        $syncCalls = [];
        $this->merchandisingSync->expects(self::exactly(3))
            ->method('sync')
            ->willReturnCallback(function (int $categoryId, int $storeId, string $storeCode) use (&$syncCalls): void {
                $syncCalls[] = [$categoryId, $storeId, $storeCode];
            });

        $this->sut->execute();

        self::assertSame([[7, 1, 'default'], [9, 1, 'default'], [12, 1, 'default']], $syncCalls);
    }

    public function test_save_rejects_disallowed_attribute_without_saving(): void
    {
        $this->params = [
            'category_id' => 5,
            'store_id' => 1,
            'rule' => ['conditions' => $this->flatConditionsPost()],
        ];

        $this->stubParsedConditions($this->conditionsTree(['not_an_allowed_attribute']));
        $this->config->method('getAdditionalAttributes')->willReturn(['color']);

        $this->ruleRepository->expects(self::never())->method('findByCategoryAndStore');
        $this->ruleRepository->expects(self::never())->method('save');
        $this->cacheInvalidator->expects(self::never())->method('invalidate');
        $this->merchandisingSync->expects(self::never())->method('sync');

        $this->sut->execute();

        self::assertIsArray($this->jsonPayload);
        self::assertFalse($this->jsonPayload['success']);
        self::assertStringContainsString('not_an_allowed_attribute', $this->jsonPayload['message']);
    }

    public function test_save_returns_error_when_conditions_payload_is_invalid(): void
    {
        $this->params = [
            'category_id' => 5,
            'store_id' => 1,
            'rule' => ['conditions' => $this->flatConditionsPost()],
        ];

        $conditionsRule = $this->createMock(ConditionsRule::class);
        $this->conditionsRuleFactory->method('create')->willReturn($conditionsRule);
        $conditionsRule->method('loadPost')->willThrowException(new \RuntimeException('malformed rule payload'));

        $this->logger->expects(self::once())->method('error');

        $this->ruleRepository->expects(self::never())->method('save');
        $this->merchandisingSync->expects(self::never())->method('sync');

        $this->sut->execute();

        self::assertSame(
            ['success' => false, 'message' => 'Invalid rule conditions payload.'],
            $this->jsonPayload,
        );
    }

    public function test_save_requires_category_id(): void
    {
        $this->params = ['category_id' => 0];

        $this->ruleRepository->expects(self::never())->method('save');

        $this->sut->execute();

        self::assertSame(
            ['success' => false, 'message' => 'category_id is required.'],
            $this->jsonPayload,
        );
    }

    /**
     * @param string[] $attributes
     * @return array<string, mixed>
     */
    private function conditionsTree(array $attributes): array
    {
        return [
            'type' => Combine::class,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => array_map(
                static fn(string $attribute): array => [
                    'type' => Product::class,
                    'attribute' => $attribute,
                    'operator' => '==',
                    'value' => 'some-value',
                ],
                $attributes,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flatConditionsPost(): array
    {
        return [
            '1' => ['type' => Combine::class, 'aggregator' => 'all', 'value' => '1'],
            '1--1' => [
                'type' => Product::class,
                'attribute' => 'sku',
                'operator' => '==',
                'value' => 'some-value',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $conditionsArray
     */
    private function stubParsedConditions(array $conditionsArray): void
    {
        $conditionsRule = $this->createMock(ConditionsRule::class);
        $this->conditionsRuleFactory->method('create')->willReturn($conditionsRule);
        $conditionsRule->method('loadPost')->willReturnSelf();

        $combine = $this->createMock(Combine::class);
        $combine->method('asArray')->willReturn($conditionsArray);
        $conditionsRule->method('getConditions')->willReturn($combine);
    }
}
