<?php

declare(strict_types=1);

namespace App\Service\Blast\Job;

use App\Entity\BlastDatabase;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\JobStatus;
use App\Exception\BestiaBaseException;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Service\Blast\BlastResultImporter;
use App\Service\Blast\BlastRunner;
use App\Service\Blast\BlastVersionReader;
use App\Service\Blast\Exception\DatabaseUnavailableException;
use App\Service\Blast\Exception\JobNotFoundException;
use App\Service\Blast\Xml\BlastXmlParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Esegue un job in attesa: pending → running → finished, oppure failed con il messaggio di
 * errore. Sostituisce l'intero ciclo legacy blastresult.php + blast.pl + file marker.
 *
 * Qualunque errore durante l'esecuzione (BLAST+ fallito, XML non valido, database rimosso,
 * errore di filesystem) chiude il job come "failed" senza lasciare risultati parziali: un job
 * non resta mai "running". Se non si riesce nemmeno a registrare il fallimento (es. MySQL non
 * raggiungibile) l'eccezione si propaga e il chiamante può ritentare.
 */
final readonly class BlastJobProcessor
{
    public const string INTERNAL_ERROR = 'The search could not be completed because of an internal error.';

    public function __construct(
        private BlastJobRepository $jobs,
        private BlastDatabaseRepository $databases,
        private BlastRunner $runner,
        private BlastVersionReader $versionReader,
        private BlastXmlParser $parser,
        private BlastResultImporter $importer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws JobNotFoundException se il job non esiste
     */
    public function process(Uuid $jobId): BlastJob
    {
        $job = $this->load($jobId);

        if ($job->getStatus() !== JobStatus::Pending) {
            // Consegna ripetuta dello stesso messaggio: il job è già stato elaborato.
            $this->logger->info('BLAST job {job} skipped: status is {status}.', ['job' => $jobId->toRfc4122(), 'status' => $job->getStatus()->value]);

            return $job;
        }

        $job->start($this->clock->now());
        $this->entityManager->flush();

        try {
            $job->recordBlastVersion($this->versionReader->read($job->getProgram()));
            $output = $this->runner->run($job, $this->resolveDatabases($job));
            $summary = $this->importer->import($job, $this->parser->parse($output));
            $job->finish($this->clock->now(), $summary->queries, $summary->hits);
            $this->entityManager->flush();

            return $job;
        } catch (Throwable $throwable) {
            return $this->fail($jobId, $throwable);
        }
    }

    private function fail(Uuid $jobId, Throwable $exception): BlastJob
    {
        $this->logger->warning('BLAST job {job} failed: {error}', ['job' => $jobId->toRfc4122(), 'error' => $exception->getMessage(), 'exception' => $exception]);

        // Scarta le entity non ancora salvate e i risultati già salvati (le FK cancellano hit e HSP).
        $this->entityManager->clear();
        $job = $this->load($jobId);
        $this->entityManager->createQuery('DELETE FROM ' . BlastQuery::class . ' q WHERE q.job = :job')
            ->setParameter('job', $job->getId(), 'uuid')
            ->execute();

        // Solo i messaggi di dominio sono pensati per l'utente: gli altri possono contenere path o SQL.
        $job->fail($this->clock->now(), $exception instanceof BestiaBaseException ? $exception->getMessage() : self::INTERNAL_ERROR);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * I database si risolvono dagli id salvati nel job, mai da path forniti dal client.
     *
     * @return list<BlastDatabase>
     */
    private function resolveDatabases(BlastJob $job): array
    {
        $resolved = [];
        $missing = [];

        foreach ($job->getDatabases() as $snapshot) {
            $database = $this->databases->find($snapshot['id']);

            if ($database instanceof BlastDatabase && $database->isActive()) {
                $resolved[] = $database;
            } else {
                $missing[] = $snapshot['code'];
            }
        }

        if ($missing !== []) {
            throw DatabaseUnavailableException::removed($missing);
        }

        return $resolved;
    }

    private function load(Uuid $jobId): BlastJob
    {
        return $this->jobs->find($jobId) ?? throw JobNotFoundException::withId($jobId);
    }
}
