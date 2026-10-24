<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BlastDatabase;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<BlastDatabase>
 */
final class BlastDatabaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlastDatabase::class);
    }

    /**
     * Database che $user può usare per una ricerca, in ordine di titolo: la stessa regola di
     * {@see BlastDatabase::isSearchableBy()}, in SQL. $user null = utente anonimo.
     */
    public function searchableBy(?User $user, string $alias = 'd'): QueryBuilder
    {
        $builder = $this->createQueryBuilder($alias)
            ->andWhere($alias . '.active = true')
            ->orderBy($alias . '.title', SortDirection::Ascending);

        if (!$user instanceof User) {
            return $builder->andWhere($alias . '.public = true');
        }

        if ($user->isAdmin()) {
            return $builder;
        }

        return $builder
            ->andWhere(\sprintf('%1$s.public = true OR :user MEMBER OF %1$s.authorizedUsers', $alias))
            ->setParameter('user', $user);
    }

    /**
     * Tutti i database riservati (per assegnarli agli utenti).
     *
     * @return list<BlastDatabase>
     */
    public function findRestricted(): array
    {
        /** @var list<BlastDatabase> $databases */
        $databases = $this->createQueryBuilder('d')->andWhere('d.public = false')->orderBy('d.title', SortDirection::Ascending)->getQuery()->getResult();

        return $databases;
    }

    /**
     * Tutti i database, attivi e non (amministrazione).
     *
     * @return list<BlastDatabase>
     */
    public function findAllByTitle(): array
    {
        /** @var list<BlastDatabase> $databases */
        $databases = $this->createQueryBuilder('d')->orderBy('d.title', SortDirection::Ascending)->getQuery()->getResult();

        return $databases;
    }
}
