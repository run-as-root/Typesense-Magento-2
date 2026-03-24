<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OpenAiModelSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openai/gpt-4o-mini', 'label' => 'GPT-4o Mini (fastest, cheapest)'],
            ['value' => 'openai/gpt-4o', 'label' => 'GPT-4o (balanced)'],
            ['value' => 'openai/gpt-4-turbo', 'label' => 'GPT-4 Turbo (most capable)'],
        ];
    }
}
