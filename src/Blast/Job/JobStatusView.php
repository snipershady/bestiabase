<?php

declare(strict_types=1);

namespace App\Blast\Job;

use App\Entity\BlastJob;
use DateTimeImmutable;

/**
 * Stato di un job esposto al client (`GET /job/{id}/status`): sostituisce il META refresh
 * ogni 10 s e i file marker del legacy.
 */
final readonly class JobStatusView
{
    public function __construct(
        public string $id,
        public string $status,
        public bool $finished,
        public string $program,
        public ?int $numQueries,
        public int $numHits,
        public ?string $error,
        public ?string $blastVersion,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {
    }

    public static function fromJob(BlastJob $job): self
    {
        return new self(
            id: $job->getId()->toRfc4122(),
            status: $job->getStatus()->value,
            finished: $job->getStatus()->isTerminal(),
            program: $job->getProgram()->value,
            numQueries: $job->getNumQueries(),
            numHits: $job->getNumHits(),
            error: $job->getErrorMessage(),
            blastVersion: $job->getBlastVersion(),
            createdAt: $job->getCreatedAt(),
            startedAt: $job->getStartedAt(),
            finishedAt: $job->getFinishedAt(),
        );
    }
}
