<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\Synonym;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\Data\SynonymInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Api\TypeSenseClientFactoryInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\Synonym\Delete;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Synonym;
use Typesense\Synonyms;

final class DeleteTest extends TestCase
{
    private SynonymRepositoryInterface&MockObject $repository;
    private Synonym&MockObject $remoteSynonym;
    private ManagerInterface&MockObject $messageManager;
    private Delete $sut;

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $params = ['collection' => 'rar_product_de_v4', 'synonym_id' => 'syn_1'];
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $params[$key] ?? $default,
        );

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory->method('create')->willReturn($redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);

        $this->remoteSynonym = $this->createMock(Synonym::class);
        $synonyms = $this->createMock(Synonyms::class);
        $synonyms->method('offsetGet')->with('syn_1')->willReturn($this->remoteSynonym);
        $collection = $this->createMock(Collection::class);
        $collection->synonyms = $synonyms;
        $collections = $this->createMock(Collections::class);
        $collections->method('offsetGet')->with('rar_product_de')->willReturn($collection);
        $client = $this->createMock(Client::class);
        $client->collections = $collections;
        $clientFactory = $this->createMock(TypeSenseClientFactoryInterface::class);
        $clientFactory->method('create')->willReturn($client);

        $this->repository = $this->createMock(SynonymRepositoryInterface::class);

        $this->sut = new Delete(
            $context,
            $this->repository,
            $clientFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function test_removes_row_and_typesense_synonym_on_the_alias(): void
    {
        $stored = $this->createMock(SynonymInterface::class);
        $this->repository->method('getByTypesenseId')->with('rar_product_de', 'syn_1')->willReturn($stored);
        $this->repository->expects(self::once())->method('delete')->with($stored);
        $this->remoteSynonym->expects(self::once())->method('delete')->willReturn([]);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $this->sut->execute();
    }

    public function test_missing_typesense_synonym_still_counts_as_deleted(): void
    {
        $this->repository->method('getByTypesenseId')->willReturn($this->createMock(SynonymInterface::class));
        $this->repository->expects(self::once())->method('delete');
        $this->remoteSynonym->method('delete')->willThrowException(new ObjectNotFound('not found'));
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $this->sut->execute();
    }

    public function test_synonym_missing_from_db_is_still_removed_from_typesense(): void
    {
        $this->repository->method('getByTypesenseId')->willThrowException(new NoSuchEntityException());
        $this->repository->expects(self::never())->method('delete');
        $this->remoteSynonym->expects(self::once())->method('delete')->willReturn([]);

        $this->sut->execute();
    }
}
