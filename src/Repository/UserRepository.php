<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Override;
use SortDirection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Aggiorna l'hash quando l'algoritmo o il suo costo cambiano (al login successivo).
     */
    #[Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User || $newHashedPassword === '') {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->changePassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<User>
     */
    public function findAllByEmail(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')->orderBy('u.email', SortDirection::Ascending)->getQuery()->getResult();

        return $users;
    }

    /**
     * Amministratori abilitati, in ordine di e-mail.
     *
     * @return list<User>
     */
    public function findAdministrators(): array
    {
        return array_values(array_filter($this->findAllByEmail(), static fn (User $user): bool => $user->isAdmin()));
    }
}
