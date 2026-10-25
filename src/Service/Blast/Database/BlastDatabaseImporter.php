<?php

declare(strict_types=1);

namespace App\Service\Blast\Database;

use App\Entity\BlastDatabase;
use App\Exception\IncompatibleProgramException;
use App\Repository\BlastDatabaseRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastTool;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\Exception\DatabaseImportException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Registra un nuovo database BLAST a partire da un FASTA (sostituisce la procedura manuale del
 * README legacy: copia in db/, makeblastdb, riga in viroblast.ini).
 *
 * Layout: `{BLAST_DB_DIR}/{nucleotide|protein}/{code}` per i volumi e `{code}.fa` accanto, come
 * FASTA sorgente per il download "entire" quando `blastdbcmd` non basta. Nel DB entrambi i path
 * sono relativi a BLAST_DB_DIR (vedi {@see BlastDatabaseStorage}).
 */
final readonly class BlastDatabaseImporter
{
    /**
     * Niente punti nel codice: i volumi sono "{code}.ext" e la pulizia dopo un errore rimuove
     * "{code}.*", che con un punto ammesso includerebbe anche un altro database ("test.v2.*").
     */
    private const string CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/';

    public function __construct(
        private BlastBinaryLocator $locator,
        private BlastProcessExecutor $executor,
        private BlastDatabaseInspector $inspector,
        private BlastDatabaseRepository $repository,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private BlastDatabaseStorage $storage,
    ) {
    }

    /**
     * @throws DatabaseImportException      codice non valido o già usato, FASTA illeggibile, path non valido
     * @throws IncompatibleProgramException se un programma richiesto non cerca su questo tipo di molecola
     * @throws BlastExecutionException      se makeblastdb o blastdbcmd falliscono
     */
    public function import(DatabaseImportRequest $request): BlastDatabase
    {
        if (preg_match(self::CODE_PATTERN, $request->code) !== 1) {
            throw DatabaseImportException::invalidCode($request->code);
        }

        if ($this->repository->findOneBy(['code' => $request->code]) instanceof BlastDatabase) {
            throw DatabaseImportException::duplicateCode($request->code);
        }

        if (!is_file($request->sourceFasta) || !is_readable($request->sourceFasta)) {
            throw DatabaseImportException::unreadableSource($request->sourceFasta);
        }

        $relativePath = $this->storage->relativePathFor($request->code, $request->molType);
        $databasePath = $this->storage->absolute($relativePath);
        $directory = \dirname($databasePath);

        if (preg_match('/\s/', $databasePath) === 1) {
            throw DatabaseImportException::invalidStoragePath($databasePath);
        }

        // Nel DB solo il path relativo a BLAST_DB_DIR: lo storage si può spostare cambiando l'env.
        $database = new BlastDatabase($request->code, $request->title, $request->molType, $relativePath, $this->clock->now(), $request->createdBy);
        $database->describe($request->description);

        if ($request->restricted) {
            $database->restrict();
        }

        foreach ($request->programs as $program) {
            $database->enableProgram($program);
        }

        if ($request->programs === []) {
            $database->enableAllCompatiblePrograms();
        }

        try {
            $this->format($request, $directory, $databasePath);
            $database->attachSourceFasta($relativePath . '.fa');
            $metadata = $this->inspector->metadata($databasePath, $request->molType);
        } catch (Throwable $throwable) {
            $this->removeVolumes($directory, $request->code);

            throw $throwable;
        }

        $database->recordStatistics($metadata->sequences, $metadata->letters);
        $this->entityManager->persist($database);
        $this->entityManager->flush();

        return $database;
    }

    private function format(DatabaseImportRequest $request, string $directory, string $databasePath): void
    {
        $sourceCopy = $databasePath . '.fa';
        $this->filesystem->mkdir($directory, 0o750);
        $this->filesystem->copy($request->sourceFasta, $sourceCopy, true);

        $command = [
            $this->locator->tool(BlastTool::MakeBlastDb),
            '-in', $sourceCopy,
            '-dbtype', $request->molType->value,
            '-title', $request->title,
            '-out', $databasePath,
            '-blastdb_version', '5',
        ];

        if ($request->parseSeqids) {
            $command[] = '-parse_seqids';
        }

        $this->executor->execute(BlastTool::MakeBlastDb->value, $command, $directory);
    }

    private function removeVolumes(string $directory, string $code): void
    {
        $files = glob($directory . '/' . $code . '.*');
        $this->filesystem->remove($files === false ? [] : $files);
    }
}
