<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Command\CreateUserCommand;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nuova password scelta con il link ricevuto per e-mail.
 */
final class NewPasswordData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: CreateUserCommand::MIN_PASSWORD_LENGTH, max: 4096)]
    public ?string $password = null;
}
