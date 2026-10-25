<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crea l'account di una registrazione pubblica. Il nuovo utente ha solo ROLE_DISABLED: può
 * accedere ma non usare nulla finché un amministratore non lo abilita. Riceve il link per
 * confermare l'indirizzo e gli amministratori ricevono un avviso.
 */
final readonly class UserRegistrar
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EmailVerifier $emailVerifier,
        private NewUserNotifier $notifier,
    ) {
    }

    public function register(RegistrationData $data): User
    {
        $user = new User(trim((string) $data->email), trim((string) $data->username), 'not-set', $this->clock->now());
        $user->changePassword($this->passwordHasher->hashPassword($user, (string) $data->password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->emailVerifier->sendLink($user);
        $this->notifier->notify($user);

        return $user;
    }
}
