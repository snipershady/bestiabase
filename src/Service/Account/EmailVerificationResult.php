<?php

declare(strict_types=1);

namespace App\Service\Account;

enum EmailVerificationResult
{
    case Verified;
    case AlreadyVerified;
    case Expired;
    case Invalid;
}
