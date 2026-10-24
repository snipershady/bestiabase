<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Blast\Exception\JobNotFoundException;
use App\Blast\Job\BlastJobProcessor;
use App\Message\RunBlastJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Esegue il job nel worker (`messenger:consume async`).
 *
 * Il lock per job impedisce che due worker elaborino lo stesso job se il messaggio viene
 * consegnato due volte: il controllo "pending" del processor da solo non basta, perché due
 * worker potrebbero leggerlo nello stesso istante.
 */
#[AsMessageHandler]
final readonly class RunBlastJobHandler
{
    public function __construct(
        private BlastJobProcessor $processor,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunBlastJob $message): void
    {
        if (!Uuid::isValid($message->jobId)) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Invalid BLAST job id "%s".', $message->jobId));
        }

        $lock = $this->lockFactory->createLock('blast-job-' . $message->jobId);

        if (!$lock->acquire()) {
            $this->logger->info('BLAST job {job} is already being processed by another worker.', ['job' => $message->jobId]);

            return;
        }

        try {
            $this->processor->process(Uuid::fromString($message->jobId));
        } catch (JobNotFoundException $jobNotFoundException) {
            // Job cancellato (es. dalla retention) prima che il worker lo prendesse: ritentare è inutile.
            throw new UnrecoverableMessageHandlingException($jobNotFoundException->getMessage(), 0, $jobNotFoundException);
        } finally {
            $lock->release();
        }
    }
}
