<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:blast:access',
    description: 'Authorizes a user to search a restricted BLAST database (or revokes it with --revoke)',
)]
final readonly class BlastDatabaseAccessCommand
{
    public function __construct(
        private BlastDatabaseRepository $databases,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Database code')]
        string $code,
        #[Argument('User email')]
        string $email,
        #[Option('Remove the authorization instead of granting it')]
        bool $revoke = false,
    ): int {
        $database = $this->databases->findOneBy(['code' => $code]);
        $user = $this->users->findOneBy(['email' => $email]);

        if (!$database instanceof BlastDatabase || !$user instanceof User) {
            $io->error(\sprintf('Unknown %s.', $database instanceof BlastDatabase ? 'user "' . $email . '"' : 'database "' . $code . '"'));

            return Command::INVALID;
        }

        if ($revoke) {
            $database->revoke($user);
        } else {
            $database->authorize($user);
        }

        $this->entityManager->flush();

        $io->success(\sprintf('%s %s "%s".', $email, $revoke ? 'can no longer search' : 'can now search', $code));

        if ($database->isPublic()) {
            $io->note('This database is public: every user can search it regardless of the authorizations.');
        }

        return Command::SUCCESS;
    }
}
