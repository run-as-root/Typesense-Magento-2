<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\BoostRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\Filter\Date as DateFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\BoostRule\Save;
use RunAsRoot\TypeSense\Model\Boost\BoostRule;
use RunAsRoot\TypeSense\Model\Boost\BoostRuleFactory;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\VirtualRule\AllowedAttributeValidator;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Combine;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRule;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product;

final class SaveTest extends TestCase
{
    private BoostRuleRepositoryInterface&MockObject $repository;
    private BoostRuleFactory&MockObject $ruleFactory;
    private ConditionsRuleFactory&MockObject $conditionsRuleFactory;
    private TypeSenseConfigInterface&MockObject $config;
    private ManagerInterface&MockObject $messageManager;
    private Redirect&MockObject $redirect;
    private TypeListInterface&MockObject $cacheTypeList;
    private LoggerInterface&MockObject $logger;
    private Save $sut;

    /** @var array<string, mixed> */
    private array $params = [];

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => $this->params[$key] ?? $default,
        );

        $this->redirect = $this->createMock(Redirect::class);
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);

        $this->repository = $this->createMock(BoostRuleRepositoryInterface::class);
        $this->ruleFactory = $this->createMock(BoostRuleFactory::class);
        $this->conditionsRuleFactory = $this->createMock(ConditionsRuleFactory::class);
        $this->config = $this->createMock(TypeSenseConfigInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $dateFilter = $this->createMock(DateFilter::class);
        $dateFilter->method('filter')->willReturnCallback(
            static fn(string $value): string => \DateTime::createFromFormat('m/d/Y', $value)->format('Y-m-d'),
        );

        $this->sut = new Save(
            $context,
            $this->repository,
            $this->ruleFactory,
            $this->conditionsRuleFactory,
            new AllowedAttributeValidator($this->config),
            $dateFilter,
            $this->cacheTypeList,
            $this->logger,
        );
    }

    public function test_save_rejects_disallowed_attributes(): void
    {
        $this->params = $this->postedForm(['rule_id' => '4']);
        $this->stubParsedConditions($this->conditionsTree(['sku', 'not_an_allowed_attribute']));
        $this->config->method('getAdditionalAttributes')->with(1)->willReturn(['color']);

        $this->repository->expects(self::never())->method('save');
        $this->cacheTypeList->expects(self::never())->method('cleanType');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with(self::callback(
                static fn($message): bool => str_contains((string) $message, 'not_an_allowed_attribute')
                    && !str_contains((string) $message, 'sku'),
            ));
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['id' => 4])->willReturnSelf();

        $this->sut->execute();
    }

    public function test_save_persists_conditions_as_json(): void
    {
        $this->params = $this->postedForm(['from_date' => '12/01/2026', 'to_date' => '']);
        $conditionsTree = $this->conditionsTree(['sku', 'color']);
        $this->stubParsedConditions($conditionsTree);
        $this->config->method('getAdditionalAttributes')->willReturn(['color']);

        $rule = $this->createMock(BoostRule::class);
        $rule->method('getId')->willReturn(11);
        $this->ruleFactory->method('create')->willReturn($rule);

        $rule->expects(self::once())->method('setName')->with('Promote house brand')->willReturnSelf();
        $rule->expects(self::once())->method('setStoreId')->with(1)->willReturnSelf();
        $rule->expects(self::once())->method('setIsActive')->with(true)->willReturnSelf();
        $rule->expects(self::once())->method('setWeight')->with(25)->willReturnSelf();
        $rule->expects(self::once())->method('setApplyToSearch')->with(true)->willReturnSelf();
        $rule->expects(self::once())->method('setApplyToCategory')->with(false)->willReturnSelf();
        $rule->expects(self::once())->method('setFromDate')->with('2026-12-01')->willReturnSelf();
        $rule->expects(self::once())->method('setToDate')->with(null)->willReturnSelf();
        $rule->expects(self::once())
            ->method('setConditionsSerialized')
            ->with(json_encode($conditionsTree, JSON_THROW_ON_ERROR))
            ->willReturnSelf();

        $this->repository->expects(self::once())->method('save')->with($rule)->willReturn($rule);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['id' => 11])->willReturnSelf();

        $this->sut->execute();
    }

    public function test_successful_save_cleans_full_page_cache(): void
    {
        $this->params = $this->postedForm();
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $rule = $this->createMock(BoostRule::class);
        $rule->method('getId')->willReturn(11);
        $this->ruleFactory->method('create')->willReturn($rule);
        $this->repository->method('save')->willReturn($rule);
        $this->redirect->method('setPath')->willReturnSelf();

        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('full_page');

        $this->sut->execute();
    }

    public function test_cache_clean_failure_after_save_shows_warning_not_error(): void
    {
        $this->params = $this->postedForm();
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $rule = $this->createMock(BoostRule::class);
        $rule->method('getId')->willReturn(11);
        $this->ruleFactory->method('create')->willReturn($rule);
        $this->repository->method('save')->willReturn($rule);
        $this->cacheTypeList->method('cleanType')->willThrowException(new \RuntimeException('redis down'));

        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->messageManager->expects(self::once())
            ->method('addWarningMessage')
            ->with(self::callback(static fn($message): bool => str_contains((string) $message, 'flush')));
        $this->logger->expects(self::once())->method('error');
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['id' => 11])->willReturnSelf();

        $this->sut->execute();
    }

    public function test_save_warns_when_a_condition_value_contains_parentheses(): void
    {
        $this->params = $this->postedForm();
        $conditionsTree = $this->conditionsTree(['sku']);
        $conditionsTree['conditions'][0]['value'] = 'Birne (alt';
        $this->stubParsedConditions($conditionsTree);
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $rule = $this->createMock(BoostRule::class);
        $rule->method('getId')->willReturn(11);
        $this->ruleFactory->method('create')->willReturn($rule);
        $this->redirect->method('setPath')->willReturnSelf();

        $this->repository->expects(self::once())->method('save')->willReturn($rule);
        $this->messageManager->expects(self::once())
            ->method('addWarningMessage')
            ->with(self::callback(static fn($message): bool => str_contains((string) $message, 'parenthes')));

        $this->sut->execute();
    }

    public function test_save_does_not_warn_without_parentheses_in_values(): void
    {
        $this->params = $this->postedForm();
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $rule = $this->createMock(BoostRule::class);
        $rule->method('getId')->willReturn(11);
        $this->ruleFactory->method('create')->willReturn($rule);
        $this->repository->method('save')->willReturn($rule);
        $this->redirect->method('setPath')->willReturnSelf();

        $this->messageManager->expects(self::never())->method('addWarningMessage');

        $this->sut->execute();
    }

    public function test_failed_save_does_not_clean_full_page_cache(): void
    {
        $this->params = $this->postedForm();
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $this->ruleFactory->method('create')->willReturn($this->createMock(BoostRule::class));
        $this->repository->method('save')->willThrowException(new \RuntimeException('db down'));
        $this->redirect->method('setPath')->willReturnSelf();

        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->sut->execute();
    }

    public function test_save_rejects_from_date_after_to_date(): void
    {
        $this->params = $this->postedForm(['from_date' => '12/31/2026', 'to_date' => '12/01/2026']);
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $this->repository->expects(self::never())->method('save');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->method('setPath')->willReturnSelf();

        $this->sut->execute();
    }

    public function test_save_accepts_negative_weight_to_demote(): void
    {
        $this->params = $this->postedForm(['weight' => '-50']);
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $rule = $this->createMock(BoostRule::class);
        $rule->method('getId')->willReturn(12);
        $this->ruleFactory->method('create')->willReturn($rule);
        $rule->expects(self::once())->method('setWeight')->with(-50)->willReturnSelf();

        $this->repository->expects(self::once())->method('save')->with($rule)->willReturn($rule);
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->redirect->method('setPath')->willReturnSelf();

        $this->sut->execute();
    }

    #[DataProvider('invalidWeightProvider')]
    public function test_save_rejects_weight_that_is_not_a_non_zero_integer(mixed $weight): void
    {
        $this->params = $this->postedForm(['rule_id' => '4', 'weight' => $weight]);
        $this->stubParsedConditions($this->conditionsTree(['sku']));
        $this->config->method('getAdditionalAttributes')->willReturn([]);

        $this->repository->expects(self::never())->method('save');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with(self::callback(static fn($message): bool => str_contains((string) $message, 'weight')));
        $this->redirect->expects(self::once())->method('setPath')->with('*/*/edit', ['id' => 4])->willReturnSelf();

        $this->sut->execute();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidWeightProvider(): array
    {
        return [
            'zero' => ['0'],
            'empty' => [''],
            'missing' => [null],
            'not a number' => ['abc'],
            'decimal' => ['1.5'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function postedForm(array $overrides = []): array
    {
        return array_merge(
            [
                'name' => ' Promote house brand ',
                'store_id' => '1',
                'is_active' => '1',
                'weight' => '25',
                'apply_to_search' => '1',
                'apply_to_category' => '0',
                'rule' => ['conditions' => ['1' => ['type' => Combine::class, 'aggregator' => 'all', 'value' => '1']]],
            ],
            $overrides,
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
     * @param array<string, mixed> $conditionsTree
     */
    private function stubParsedConditions(array $conditionsTree): void
    {
        $combine = $this->createMock(Combine::class);
        $combine->method('asArray')->willReturn($conditionsTree);

        $conditionsRule = $this->createMock(ConditionsRule::class);
        $conditionsRule->method('loadPost')->willReturnSelf();
        $conditionsRule->method('getConditions')->willReturn($combine);
        $this->conditionsRuleFactory->method('create')->willReturn($conditionsRule);
    }
}
