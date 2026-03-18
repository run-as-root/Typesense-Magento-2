<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class LandingPageActions extends Column
{
    private const URL_PATH_EDIT   = 'typesense/landingpage/edit';
    private const URL_PATH_DELETE = 'typesense/landingpage/delete';

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

        foreach ($dataSource['data']['items'] as &$item) {
            $name = $this->getData('name');
            $id   = (int) ($item['id'] ?? 0);

            $item[$name]['edit'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['id' => $id]),
                'label' => __('Edit'),
            ];

            $item[$name]['delete'] = [
                'href'    => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['id' => $id]),
                'label'   => __('Delete'),
                'confirm' => [
                    'title'   => __('Delete Landing Page'),
                    'message' => __('Are you sure you want to delete landing page with ID: %1?', $id),
                ],
                'post' => true,
            ];
        }

        return $dataSource;
    }
}
