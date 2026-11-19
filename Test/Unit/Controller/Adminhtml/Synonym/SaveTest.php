<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\Synonym;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\SynonymRepositoryInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\Synonym\Save;
use RunAsRoot\TypeSense\Model\Synonym\Synonym;
use RunAsRoot\TypeSense\Model\Synonym\SynonymFactory;
use RunAsRoot\TypeSense\Model\Synonym\SynonymPusher;

final class SaveTest extends TestCase
{
    private SynonymRepositoryInterface&MockObject $repository;
    private SynonymFactory&MockObject $synonymFactory;
    private SynonymPusher&MockObject $pusher;
    private ManagerInterface&MockObject $messageManager;
    private Synonym&MockObject $synonym;
    private Save $sut;

    /** @var array<string, mixed> */
    private array $params = [];

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn(string $key, $default = null) => $this->params[$key] ?? $default,
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

        $this->repository = $this->createMock(SynonymRepositoryInterface::class);
        $this->synonymFactory = $this->createMock(SynonymFactory::class);
        $this->pusher = $this->createMock(SynonymPusher::class);

        $this->synonym = $this->createMock(Synonym::class);
        $this->synonym->method('getTypesenseId')->willReturn('syn_1');
        $this->synonymFactory->method('create')->willReturn($this->synonym);

        $this->sut = new Save(
            $context,
            $this->repository,
            $this->synonymFactory,
            $this->pusher,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function test_persists_against_alias_then_pushes_to_alias(): void
    {
        $this->params = [
            'collection' => 'rar_product_de_v4',
            'type' => 'one-way',
            'root' => 'smartphone',
            'synonyms' => 'phone, mobile , ',
        ];

        $this->synonym->expects(self::once())->method('setCollectionName')->with('rar_product_de')->willReturnSelf();
        $this->synonym->expects(self::once())
            ->method('setTypesenseId')
            ->with(self::matchesRegularExpression('/^syn_[0-9a-f]{12}$/'))
            ->willReturnSelf();
        $this->synonym->expects(self::once())->method('setType')->with('one-way')->willReturnSelf();
        $this->synonym->expects(self::once())->method('setRoot')->with('smartphone')->willReturnSelf();
        $this->synonym->expects(self::once())->method('setSynonyms')->with(['phone', 'mobile'])->willReturnSelf();

        $calls = [];
        $this->repository->expects(self::once())
            ->method('save')
            ->with($this->synonym)
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'save';

                return $this->synonym;
            });
        $this->pusher->expects(self::once())
            ->method('push')
            ->with($this->synonym, 'rar_product_de')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'push';
            });
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $this->sut->execute();

        self::assertSame(['save', 'push'], $calls);
    }

    public function test_multi_way_synonym_is_stored_without_root(): void
    {
        $this->params = ['collection' => 'rar_product_de', 'type' => 'multi', 'root' => 'x', 'synonyms' => 'tv,television'];

        $this->synonym->method('setCollectionName')->willReturnSelf();
        $this->synonym->method('setTypesenseId')->willReturnSelf();
        $this->synonym->expects(self::once())->method('setType')->with('multi')->willReturnSelf();
        $this->synonym->expects(self::once())->method('setRoot')->with(null)->willReturnSelf();
        $this->synonym->method('setSynonyms')->willReturnSelf();
        $this->repository->method('save')->willReturn($this->synonym);

        $this->pusher->expects(self::once())->method('push')->with($this->synonym, 'rar_product_de');

        $this->sut->execute();
    }

    public function test_does_not_push_when_persisting_fails(): void
    {
        $this->params = ['collection' => 'rar_product_de', 'type' => 'multi', 'synonyms' => 'tv,television'];

        $this->synonym->method('setCollectionName')->willReturnSelf();
        $this->synonym->method('setTypesenseId')->willReturnSelf();
        $this->synonym->method('setType')->willReturnSelf();
        $this->synonym->method('setRoot')->willReturnSelf();
        $this->synonym->method('setSynonyms')->willReturnSelf();
        $this->repository->method('save')->willThrowException(new CouldNotSaveException(__('db down')));

        $this->pusher->expects(self::never())->method('push');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $this->sut->execute();
    }

    public function test_rejects_empty_synonym_list_without_persisting(): void
    {
        $this->params = ['collection' => 'rar_product_de', 'type' => 'multi', 'synonyms' => ' , '];

        $this->repository->expects(self::never())->method('save');
        $this->pusher->expects(self::never())->method('push');

        $this->sut->execute();
    }
}
