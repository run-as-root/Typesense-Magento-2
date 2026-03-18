<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Api;

use Typesense\Client;

interface TypeSenseClientFactoryInterface
{
    public function create(?int $storeId = null): Client;
}
