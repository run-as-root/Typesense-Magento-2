<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Controller\Adminhtml\BoostRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RunAsRoot\TypeSense\Api\BoostRuleRepositoryInterface;
use RunAsRoot\TypeSense\Api\Data\BoostRuleInterface;
use RunAsRoot\TypeSense\Controller\Adminhtml\BoostRule\Delete;

final class DeleteTest extends TestCase
{
    private BoostRuleRepositoryInterface&MockObject $repository;
    private TypeListInterface&MockObject $cacheTypeList;
    private ManagerInterface&MockObject $messageManager;
    private Delete $sut;

    protected function setUp(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => ['id' => '6'][$key] ?? $default,
        );

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);

        $this->repository = $this->createMock(BoostRuleRepositoryInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        $this->sut = new Delete(
            $context,
            $this->repository,
            $this->cacheTypeList,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function test_successful_delete_cleans_full_page_cache(): void
    {
        $rule = $this->createMock(BoostRuleInterface::class);
        $this->repository->method('getById')->with(6)->willReturn($rule);
        $this->repository->expects(self::once())->method('delete')->with($rule)->willReturn(true);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('full_page');

        $this->sut->execute();
    }

    public function test_missing_rule_does_not_clean_full_page_cache(): void
    {
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->sut->execute();
    }

    public function test_failed_delete_does_not_clean_full_page_cache(): void
    {
        $this->repository->method('getById')->willReturn($this->createMock(BoostRuleInterface::class));
        $this->repository->method('delete')->willThrowException(new CouldNotDeleteException(__('db down')));
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->sut->execute();
    }
}
