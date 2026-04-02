<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use RunAsRoot\TypeSense\Model\Conversation\AdminConversationModelManager;
use RunAsRoot\TypeSense\Model\Conversation\ConversationModelManager;

class ConversationConfigSave implements ObserverInterface
{
    public function __construct(
        private readonly ConversationModelManager $conversationModelManager,
        private readonly AdminConversationModelManager $adminConversationModelManager,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->conversationModelManager->sync();
        $this->adminConversationModelManager->sync();
    }
}
