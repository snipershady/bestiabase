<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\JobStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BlastJob>
 */
final class BlastJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlastJob::class);
    }

    /**
     * Job di un utente, dal più recente.
     *
     * @return list<BlastJob>
     */
    public function findForUser(User $user, int $offset, int $limit): array
    {
        /** @var list<BlastJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->andWhere('j.user = :user')
            ->setParameter('user', $user)
            ->orderBy('j.createdAt', SortDirection::Descending)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $jobs;
    }

    /**
     * Ultimi job di tutti gli utenti (amministrazione), con il proprietario.
     *
     * @return list<BlastJob>
     */
    public function findRecent(int $offset, int $limit): array
    {
        /** @var list<BlastJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->leftJoin('j.user', 'u')
            ->addSelect('u')
            ->orderBy('j.createdAt', SortDirection::Descending)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $jobs;
    }

    /**
     * Id dei job terminati creati prima di $before, dal più vecchio. I job in attesa o in
     * esecuzione non scadono mai: un worker potrebbe ancora lavorarci.
     *
     * @return list<Uuid>
     */
    public function findExpiredIds(DateTimeImmutable $before, int $limit): array
    {
        /** @var list<array{id: Uuid}> $rows */
        $rows = $this->createQueryBuilder('j')
            ->select('j.id')
            ->andWhere('j.createdAt < :before')
            ->andWhere('j.status IN (:terminal)')
            ->setParameter('before', $before)
            ->setParameter('terminal', array_map(
                static fn (JobStatus $status): string => $status->value,
                array_filter(JobStatus::cases(), static fn (JobStatus $status): bool => $status->isTerminal()),
            ))
            ->orderBy('j.createdAt', SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_column($rows, 'id');
    }
}
