<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\BoostRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\Filter\Date as DateFilter;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Model\Boost\BoostRuleFactory;
use RunAsRoot\TypeSense\Model\VirtualRule\AllowedAttributeValidator;
use RunAsRoot\TypeSense\Model\VirtualRule\Condition\ConditionsRuleFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::boost_rules';

    // The rules are rendered into the storefront search config, which the full page cache keeps.
    private const FULL_PAGE_CACHE_TYPE = 'full_page';

    public function __construct(
        Context $context,
        private readonly BoostRuleRepositoryInterface $repository,
        private readonly BoostRuleFactory $ruleFactory,
        private readonly ConditionsRuleFactory $conditionsRuleFactory,
        private readonly AllowedAttributeValidator $allowedAttributeValidator,
        private readonly DateFilter $dateFilter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('rule_id');
        $redirect = $this->resultRedirectFactory->create();
        $backToForm = fn(): Redirect => $redirect->setPath('*/*/edit', $id ? ['id' => $id] : []);

        $name = trim((string) $this->getRequest()->getParam('name', ''));
        if ($name === '') {
            $this->messageManager->addErrorMessage(__('Rule name is required.'));

            return $backToForm();
        }

        $weight = $this->parseWeight($this->getRequest()->getParam('weight'));
        if ($weight === null) {
            $this->messageManager->addErrorMessage(
                __('The weight must be a non-zero whole number; negative weights demote matching products.'),
            );

            return $backToForm();
        }

        try {
            $conditions = $this->parseConditions();
        } catch (\Throwable $e) {
            $this->logger->error(
                'BoostRule Save: failed to parse rule conditions - ' . $e->getMessage(),
                ['exception' => $e],
            );
            $this->messageManager->addErrorMessage(__('Invalid rule conditions payload.'));

            return $backToForm();
        }

        $storeId = (int) $this->getRequest()->getParam('store_id', 0);
        $disallowed = $this->allowedAttributeValidator->findDisallowedAttributes($conditions, $storeId ?: null);
        if ($disallowed !== []) {
            $this->messageManager->addErrorMessage(
                __(
                    'The following attributes are not allowed in boosting rule conditions: %1',
                    implode(', ', $disallowed),
                ),
            );

            return $backToForm();
        }

        try {
            $fromDate = $this->filterDate($this->getRequest()->getParam('from_date'));
            $toDate = $this->filterDate($this->getRequest()->getParam('to_date'));

            if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
                $this->messageManager->addErrorMessage(__('The "From" date must not be after the "To" date.'));

                return $backToForm();
            }

            $rule = $id ? $this->repository->getById($id) : $this->ruleFactory->create();
            $rule->setName($name);
            $rule->setStoreId($storeId);
            $rule->setIsActive((bool) $this->getRequest()->getParam('is_active', false));
            $rule->setWeight($weight);
            $rule->setApplyToSearch((bool) $this->getRequest()->getParam('apply_to_search', false));
            $rule->setApplyToCategory((bool) $this->getRequest()->getParam('apply_to_category', false));
            $rule->setFromDate($fromDate);
            $rule->setToDate($toDate);
            $rule->setConditionsSerialized(json_encode($conditions, JSON_THROW_ON_ERROR));

            $savedId = (int) $this->repository->save($rule)->getId();
            if ($this->hasParenthesisInValues($conditions)) {
                $this->messageManager->addWarningMessage(
                    __('The storefront ignores this rule while a condition value contains parentheses.'),
                );
            }
            if ($this->cleanFullPageCache()) {
                $this->messageManager->addSuccessMessage(__('The boosting rule has been saved.'));
            } else {
                $this->messageManager->addWarningMessage(
                    __('The boosting rule has been saved, but cleaning the page cache failed; flush it manually.'),
                );
            }

            return $redirect->setPath('*/*/edit', ['id' => $savedId]);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This boosting rule no longer exists.'));

            return $redirect->setPath('*/*/index');
        } catch (\Throwable $e) {
            $this->logger->error('BoostRule Save error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('An error occurred while saving: %1', $e->getMessage()));

            return $backToForm();
        }
    }

    private function cleanFullPageCache(): bool
    {
        try {
            $this->cacheTypeList->cleanType(self::FULL_PAGE_CACHE_TYPE);
        } catch (\Throwable $e) {
            $this->logger->error(
                'BoostRule Save: page cache clean failed - ' . $e->getMessage(),
                ['exception' => $e],
            );

            return false;
        }

        return true;
    }

    /**
     * Mirrors BoostSortBuilder's check on the compiled filter, so the admin learns at save time.
     *
     * @param array<string, mixed> $node
     */
    private function hasParenthesisInValues(array $node): bool
    {
        $children = $node['conditions'] ?? null;
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child) && $this->hasParenthesisInValues($child)) {
                    return true;
                }
            }

            return false;
        }

        $values = is_array($node['value'] ?? null) ? $node['value'] : [$node['value'] ?? ''];
        foreach ($values as $value) {
            if (is_string($value) && strpbrk($value, '()') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The condition inputs are named rule[conditions][...] by Magento's rule renderer and reach
     * this action through the UI form's data-form-part collection, still in the flat POST shape
     * loadPost() expects.
     *
     * @return array<string, mixed>
     */
    private function parseConditions(): array
    {
        $rulePost = $this->getRequest()->getParam('rule');
        $conditionsPost = (is_array($rulePost) && is_array($rulePost['conditions'] ?? null))
            ? $rulePost['conditions']
            : [];

        $conditionsRule = $this->conditionsRuleFactory->create();
        $conditionsRule->loadPost(['conditions' => $conditionsPost]);

        return $conditionsRule->getConditions()->asArray();
    }

    /**
     * Bounded to the signed INT column; 0 is rejected because it would be a rule without effect.
     */
    private function parseWeight(mixed $value): ?int
    {
        $weight = is_string($value)
            ? filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]],
            )
            : false;

        return $weight === false || $weight === 0 ? null : $weight;
    }

    private function filterDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->dateFilter->filter($value);
    }
}
