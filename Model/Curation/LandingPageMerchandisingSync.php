<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Curation;

use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\CollectionNameResolverInterface;
use RunAsRoot\TypeSense\Api\LandingPageRepositoryInterface;
use RunAsRoot\TypeSense\Api\OverrideManagerInterface;

class LandingPageMerchandisingSync
{
    public function __construct(
        private readonly OverrideManagerInterface $overrideManager,
        private readonly CollectionNameResolverInterface $collectionNameResolver,
        private readonly LandingPageRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(int $landingPageId, int $storeId, string $storeCode): void
    {
        $landingPage = $this->repository->getById($landingPageId);
        $collectionName = $this->collectionNameResolver->resolve('product', $storeCode, $storeId);
        $overrideId = sprintf('landing_%d', $landingPageId);

        if (!$landingPage->isActive()) {
            $this->logger->info(sprintf(
                'Landing page %d is inactive — deleting override %s',
                $landingPageId,
                $overrideId,
            ));
            $this->overrideManager->deleteOverride($collectionName, $overrideId);
            return;
        }

        $payload = [
            'rule' => [
                'query' => $landingPage->getQuery(),
                'match' => 'exact',
                'filter_by' => $landingPage->getFilterBy() ?? '',
            ],
            'includes' => $landingPage->getIncludes(),
            'excludes' => $landingPage->getExcludes(),
        ];

        $this->logger->info(sprintf(
            'Upserting override %s on collection %s for landing page "%s"',
            $overrideId,
            $collectionName,
            $landingPage->getQuery(),
        ));

        $this->overrideManager->createOverride($collectionName, $overrideId, $payload);
    }
}
