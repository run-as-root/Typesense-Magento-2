<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\BoostRule\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * A plain <url> form button navigates with GET, but Delete only accepts POST; deleteConfirm()
 * with post data submits it as a form-keyed POST instead.
 */
class DeleteButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $id = (int) $this->request->getParam('id');

        if ($id === 0) {
            return [];
        }

        $message = $this->escaper->escapeJs(
            $this->escaper->escapeHtml(__('Are you sure you want to delete this boosting rule?')),
        );
        $url = $this->escaper->escapeJs($this->urlBuilder->getUrl('*/*/delete', ['id' => $id]));

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf("deleteConfirm('%s', '%s', {data: {}})", $message, $url),
            'sort_order' => 20,
        ];
    }
}
