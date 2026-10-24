<?php

declare(strict_types=1);

namespace App\Blast;

use App\Blast\Database\BlastDatabaseStorage;
use App\Blast\Exception\BlastExecutionException;
use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Search\SearchParametersNormalizer;
use App\Entity\BlastDatabase;
use App\Entity\BlastJob;
use App\Enum\SearchType;
use App\Exception\IncompatibleProgramException;
use InvalidArgumentException;

/**
 * Esegue la ricerca di un job (porting di blast.pl L132–154) e restituisce il path dell'XML.
 *
 * A differenza del legacy, l'esito si decide dall'exit code e dal timeout, mai dalla presenza
 * di file marker o dal contenuto di stderr.
 */
final readonly class BlastRunner
{
    public function __construct(
        private BlastBinaryLocator $locator,
        private BlastCommandBuilder $commandBuilder,
        private SearchParametersNormalizer $parametersNormalizer,
        private BlastProcessExecutor $executor,
        private JobWorkspace $workspace,
        private BlastDatabaseStorage $storage,
    ) {
    }

    /**
     * @param list<BlastDatabase> $databases database registrati scelti per il job (già risolti lato server)
     *
     * @throws BlastExecutionException          se makeblastdb o il programma BLAST falliscono
     * @throws IncompatibleProgramException     se un database non è abilitato per il programma del job
     * @throws InvalidSearchParametersException se i parametri salvati non sono validi per il programma
     * @throws InvalidArgumentException         se il job non ha alcun database su cui cercare
     *
     * @return string path dell'output XML (`-outfmt 5`)
     */
    public function run(BlastJob $job, array $databases): string
    {
        $program = $job->getProgram();
        $databasePaths = [];

        foreach ($databases as $database) {
            if (!$database->supports($program)) {
                throw IncompatibleProgramException::forDatabase($program, $database->getMolType());
            }

            $databasePaths[] = $this->storage->volumesOf($database);
        }

        $id = $job->getId();
        $directory = $this->workspace->prepare($id);
        $queryFile = $this->workspace->write($id, JobWorkspace::QUERY_FILE, $job->getQueryFasta());

        $subjectFasta = $job->getSubjectFasta();

        if ($subjectFasta !== null) {
            $databasePaths[] = $this->buildSubjectDatabase($job, $subjectFasta, $directory);
        }

        if ($databasePaths === []) {
            throw new InvalidArgumentException('A BLAST job needs at least one database or an uploaded subject.');
        }

        $parameters = $job->getSearchType() === SearchType::Advanced
            ? $this->parametersNormalizer->denormalize($job->getParameters())
            : null;

        $outputFile = $this->workspace->path($id, JobWorkspace::OUTPUT_FILE);
        $command = $this->commandBuilder->build($program, $parameters, $databasePaths, $queryFile, $outputFile);

        $this->executor->execute($program->value, $command, $directory);

        return $outputFile;
    }

    /**
     * Formatta il FASTA soggetto caricato (blast.pl L133–148). Senza -parse_seqids, come il
     * legacy: i defline caricati dagli utenti non sono necessariamente seqid NCBI validi.
     */
    private function buildSubjectDatabase(BlastJob $job, string $subjectFasta, string $directory): string
    {
        $id = $job->getId();
        $subjectFile = $this->workspace->write($id, JobWorkspace::SUBJECT_FILE, $subjectFasta);
        $databasePath = $this->workspace->path($id, JobWorkspace::SUBJECT_DATABASE);

        $this->executor->execute(BlastTool::MakeBlastDb->value, [
            $this->locator->tool(BlastTool::MakeBlastDb),
            '-in', $subjectFile,
            '-dbtype', $job->getProgram()->databaseMolType()->value,
            '-out', $databasePath,
        ], $directory);

        return $databasePath;
    }
}
