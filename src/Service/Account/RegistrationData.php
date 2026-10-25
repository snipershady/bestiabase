<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Command\CreateUserCommand;
use App\Entity\User;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaSolved;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Registrazione pubblica: l'account nasce disabilitato finché un amministratore non lo abilita.
 */
#[UniqueEntity(fields: ['email'], message: 'This email address is already registered.', entityClass: User::class)]
#[UniqueEntity(fields: ['username'], message: 'This username is already taken.', entityClass: User::class)]
final class RegistrationData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[DeliverableEmail]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    public ?string $username = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: CreateUserCommand::MIN_PASSWORD_LENGTH, max: 4096)]
    public ?string $password = null;

    /** Soluzione della proof of work, scritta dal widget ALTCHA. */
    #[AltchaSolved(AltchaPurpose::Registration)]
    public ?string $altcha = null;
}
