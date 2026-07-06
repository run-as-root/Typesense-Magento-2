<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Controller\Adminhtml\CategoryVirtualRule;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CategoryVirtualRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\CategoryVirtualRuleInterface;

/**
 * Returns the persisted virtual-rule state for a category+store as JSON — the same data
 * Block\Adminhtml\Category\VirtualRule reads to render the tab: isVirtual(),
 * getVirtualCategoryRootId(), getVirtualCategoryRootName(), and the decoded conditions tree.
 *
 * Unlike CategoryMerchandiser's Load, the category edit page itself never calls this endpoint: the
 * virtual-rule tab is Magento's real server-rendered rule builder (see the block's docblock), so
 * its state is already embedded in the ui_component htmlContent field on every category
 * edit/tab-switch request. This controller exists for API-style consumers that need the current
 * rule without re-fetching and re-parsing the whole category edit form's HTML — e.g. e2e tests —
 * and for parity with the merchandiser tab's Load/Save pair.
 */
class Load extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'RunAsRoot_TypeSense::virtual_category';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CategoryVirtualRuleRepositoryInterface $ruleRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly JsonSerializer $jsonSerializer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->jsonFactory->create();

        $categoryId = (int) $this->getRequest()->getParam('category_id', 0);
        $storeId = (int) $this->getRequest()->getParam('store_id', 0);

        if ($categoryId === 0) {
            return $resultJson->setData($this->emptyState());
        }

        try {
            $rule = $this->ruleRepository->findByCategoryAndStore($categoryId, $storeId);

            if ($rule === null) {
                return $resultJson->setData($this->emptyState());
            }

            return $resultJson->setData([
                'is_virtual' => $rule->isVirtual(),
                'virtual_category_root_id' => $rule->getVirtualCategoryRootId(),
                'virtual_category_root_name' => $this->getRootName($rule, $storeId),
                'conditions' => $this->decodeConditions($rule),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CategoryVirtualRule Load error: ' . $e->getMessage(),
                ['exception' => $e],
            );

            return $resultJson->setData([...$this->emptyState(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{is_virtual: bool, virtual_category_root_id: null, virtual_category_root_name: null, conditions: null}
     */
    private function emptyState(): array
    {
        return [
            'is_virtual' => false,
            'virtual_category_root_id' => null,
            'virtual_category_root_name' => null,
            'conditions' => null,
        ];
    }

    private function getRootName(CategoryVirtualRuleInterface $rule, int $storeId): ?string
    {
        $rootId = $rule->getVirtualCategoryRootId();

        if ($rootId === null) {
            return null;
        }

        try {
            return $this->categoryRepository->get($rootId, $storeId)->getName();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeConditions(CategoryVirtualRuleInterface $rule): ?array
    {
        $serialized = $rule->getConditionsSerialized();

        if ($serialized === null || $serialized === '') {
            return null;
        }

        try {
            $decoded = $this->jsonSerializer->unserialize($serialized);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
