<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Collection;

/**
 * Collection-level state could not be re-created on a new collection, which therefore did not go live.
 */
class ApplierFailedException extends \RuntimeException
{
}
