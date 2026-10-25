<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Command\CreateUserCommand;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cambio password di un utente già autenticato: serve la password attuale.
 */
final class ChangePasswordData
{
    #[Assert\NotBlank]
    #[UserPassword(message: 'The current password is not correct.')]
    public ?string $currentPassword = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: CreateUserCommand::MIN_PASSWORD_LENGTH, max: 4096)]
    #[Assert\NotEqualTo(propertyPath: 'currentPassword', message: 'The new password must differ from the current one.')]
    public ?string $password = null;
}
