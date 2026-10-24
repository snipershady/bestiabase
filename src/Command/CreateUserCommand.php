<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a user (use --admin for an administrator); the password is asked without echoing it',
)]
final readonly class CreateUserCommand
{
    public const int MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address, used to sign in')]
        string $email,
        #[Argument('Name shown in the interface')]
        string $username,
        #[Option('Grant the administrator role (all databases, users and jobs)')]
        bool $admin = false,
    ): int {
        $errors = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);

        if (\count($errors) > 0 || trim($username) === '') {
            $io->error('Enter a valid email address and a non-empty username.');

            return Command::INVALID;
        }

        if ($this->users->findOneBy(['email' => $email]) instanceof User || $this->users->findOneBy(['username' => $username]) instanceof User) {
            $io->error('A user with this email or username already exists.');

            return Command::FAILURE;
        }

        // Mai come argomento: finirebbe nella history della shell e nell'elenco dei processi.
        $password = $io->askHidden(\sprintf('Password (at least %d characters)', self::MIN_PASSWORD_LENGTH));
        $confirmation = $io->askHidden('Repeat the password');

        if (!\is_string($password) || \strlen($password) < self::MIN_PASSWORD_LENGTH || $password !== $confirmation) {
            $io->error(\sprintf('The passwords must match and be at least %d characters long.', self::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $user = new User($email, trim($username), 'not-set', $this->clock->now());
        $user->changePassword($this->passwordHasher->hashPassword($user, $password));

        if ($admin) {
            $user->grantRole(User::ROLE_ADMIN);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%s "%s" created.', $admin ? 'Administrator' : 'User', $email));

        return Command::SUCCESS;
    }
}
