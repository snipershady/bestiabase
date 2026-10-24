<?php

declare(strict_types=1);

use App\Kernel;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

require_once __DIR__ . '/../vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    $input = new EffectivePrimitiveTypeIdentifierService();

    return new Kernel(
        $input->getStringValueFromArray('APP_ENV', $context),
        $input->getBoolValueFromArray('APP_DEBUG', $context),
    );
};
