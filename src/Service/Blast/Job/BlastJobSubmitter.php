<?php

declare(strict_types=1);

namespace App\Service\Blast\Job;

use App\Entity\BlastJob;
use App\Service\Message\RunBlastJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Crea il job, lo salva e lo accoda per un worker: la richiesta HTTP risponde subito e il
 * client segue l'avanzamento con l'endpoint di stato (sostituisce `perl blast.pl … &`).
 */
final readonly class BlastJobSubmitter
{
    public function __construct(
        private BlastJobFactory $factory,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Le eccezioni di validazione di {@see BlastJobFactory::create()} arrivano al chiamante:
     * in quel caso nessun job viene salvato né accodato.
     */
    public function submit(BlastSearchRequest $request): BlastJob
    {
        $job = $this->factory->create($request);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Il messaggio parte dopo il flush: il worker deve trovare il job nel DB.
        $this->bus->dispatch(new RunBlastJob($job->getId()->toRfc4122()));

        return $job;
    }
}
