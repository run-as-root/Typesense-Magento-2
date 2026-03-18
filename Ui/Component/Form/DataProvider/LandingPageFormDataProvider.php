<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;
use RunAsRoot\TypeSense\Model\ResourceModel\LandingPage\CollectionFactory;

class LandingPageFormDataProvider extends AbstractDataProvider
{
    /** @var array<int, array<string, mixed>> */
    private array $loadedData = [];

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly CollectionFactory $collectionFactory,
        private readonly LandingPageRepositoryInterface $repository,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $this->collectionFactory->create();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getData(): array
    {
        if (!empty($this->loadedData)) {
            return $this->loadedData;
        }

        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        foreach ($this->getCollection()->getItems() as $item) {
            $itemData = $item->getData();

            // Decode JSON fields to strings for form display
            if (!empty($itemData['includes']) && is_string($itemData['includes'])) {
                $decoded = json_decode($itemData['includes'], true);
                $itemData['includes'] = is_array($decoded) ? json_encode($decoded) : '[]';
            } else {
                $itemData['includes'] = '[]';
            }

            if (!empty($itemData['excludes']) && is_string($itemData['excludes'])) {
                $decoded = json_decode($itemData['excludes'], true);
                $itemData['excludes'] = is_array($decoded) ? json_encode($decoded) : '[]';
            } else {
                $itemData['excludes'] = '[]';
            }

            if (!empty($itemData['banner_config']) && is_string($itemData['banner_config'])) {
                $decoded = json_decode($itemData['banner_config'], true);
                $itemData['banner_config'] = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT) : '{}';
            } else {
                $itemData['banner_config'] = '{}';
            }

            $id = (int) $item->getId();
            $this->loadedData[$id] = $itemData;
        }

        return $this->loadedData;
    }
}
