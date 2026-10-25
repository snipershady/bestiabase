<?php

declare(strict_types=1);

namespace App\Service\Blast\Job;

use App\Entity\BlastJob;
use App\Repository\BlastJobRepository;
use App\Service\Blast\JobWorkspace;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Retention dei job (sostituisce cleandata.sh, che cancellava tutto data/ senza criterio):
 * rimuove i job terminati più vecchi di N giorni, i loro risultati (FK in cascata) e il
 * workspace su disco.
 */
final readonly class JobCleaner
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private BlastJobRepository $jobs,
        private JobWorkspace $workspace,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param positive-int $days
     *
     * @return int numero di job rimossi (o che verrebbero rimossi, con $dryRun)
     */
    public function purgeOlderThan(int $days, bool $dryRun = false): int
    {
        $before = $this->clock->now()->sub(new DateInterval('P' . $days . 'D'));
        $removed = 0;

        do {
            $ids = $this->jobs->findExpiredIds($before, self::BATCH_SIZE);

            if ($dryRun) {
                return \count($ids) + $removed;
            }

            foreach ($ids as $id) {
                $this->workspace->remove($id);
            }

            if ($ids !== []) {
                $this->entityManager->createQuery('DELETE FROM ' . BlastJob::class . ' j WHERE j.id IN (:ids)')
                    ->setParameter('ids', array_map(static fn ($id): string => $id->toBinary(), $ids))
                    ->execute();
            }

            $removed += \count($ids);
        } while (\count($ids) === self::BATCH_SIZE);

        return $removed;
    }
}
