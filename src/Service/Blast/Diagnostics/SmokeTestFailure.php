<?php

declare(strict_types=1);

namespace App\Service\Blast\Diagnostics;

use App\Exception\BestiaBaseException;
use RuntimeException;

final class SmokeTestFailure extends RuntimeException implements BestiaBaseException
{
}
