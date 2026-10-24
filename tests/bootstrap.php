<?php

declare(strict_types=1);

use Doctrine\Deprecations\Deprecation;
use Symfony\Component\Dotenv\Dotenv;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

require __DIR__ . '/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__ . '/../.env');

// Le deprecazioni Doctrine sono silenziose di default: nei test devono emergere (failOnDeprecation).
Deprecation::enableWithTriggerError();

if (new EffectivePrimitiveTypeIdentifierService()->getBoolValueFromArray('APP_DEBUG', $_SERVER)) {
    umask(0o000);
}
