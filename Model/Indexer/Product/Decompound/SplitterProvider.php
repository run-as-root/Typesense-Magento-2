<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Decompound;

use RunAsRoot\GermanCompoundSplitter\Splitter;

/**
 * Splitter is final, so Magento cannot generate a proxy for it; this lazy holder plays that role so the
 * ~20 MB of dictionary data is only read in stores that enable decompounding.
 */
class SplitterProvider
{
    private ?Splitter $splitter = null;

    public function get(): Splitter
    {
        return $this->splitter ??= new Splitter();
    }
}
