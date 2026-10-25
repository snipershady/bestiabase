<?php

declare(strict_types=1);

namespace App\Service\Account;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Richiesta del link per reimpostare la password.
 */
final class ForgotPasswordData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
