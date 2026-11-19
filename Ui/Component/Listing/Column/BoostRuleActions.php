<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class BoostRuleActions extends Column
{
    private const URL_PATH_EDIT = 'typesense/boostrule/edit';
    private const URL_PATH_DELETE = 'typesense/boostrule/delete';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) ($item['rule_id'] ?? 0);

            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['id' => $id]),
                'label' => __('Edit'),
            ];
            $item[$name]['delete'] = [
                'href' => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['id' => $id]),
                'label' => __('Delete'),
                'confirm' => [
                    'title' => __('Delete Boosting Rule'),
                    'message' => __('Are you sure you want to delete the boosting rule with ID %1?', $id),
                ],
                'post' => true,
            ];
        }

        return $dataSource;
    }
}
