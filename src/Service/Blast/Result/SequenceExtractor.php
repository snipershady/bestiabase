<?php

declare(strict_types=1);

namespace App\Service\Blast\Result;

use App\Entity\BlastDatabase;
use App\Entity\BlastHit;
use App\Entity\BlastJob;
use App\Enum\DownloadSequenceType;
use App\Repository\BlastDatabaseRepository;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\BlastTool;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Exception\BlastExecutionException;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\SequenceNames;
use Generator;
use Psr\Log\LoggerInterface;
use SplFileObject;

/**
 * Sequenze soggetto in FASTA per gli hit selezionati (sostituisce sequence.php).
 *
 * - "mapping": un record per HSP con la regione allineata (senza gap) e le coordinate di soggetto e
 *   query nel formato legacy; per tblastn/tblastx la sequenza è la traduzione allineata.
 * - "entire": un record per soggetto, cercato in ordine con `blastdbcmd` nei database del job (per
 *   accessione), nel FASTA soggetto caricato con il job e nei FASTA sorgente dei database registrati
 *   (database senza -parse_seqids). I soggetti non trovati vengono saltati e registrati nel log.
 */
final readonly class SequenceExtractor
{
    public const int LINE_WIDTH = 80;

    private const string ID_FILE = 'download-ids.txt';

    public function __construct(
        private BlastBinaryLocator $locator,
        private BlastProcessExecutor $executor,
        private BlastDatabaseStorage $storage,
        private BlastDatabaseRepository $databases,
        private JobWorkspace $workspace,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<BlastHit> $hits hit del job, nell'ordine in cui vanno scritti
     *
     * @return Generator<int, string> record FASTA, ognuno terminato da un a capo
     */
    public function extract(BlastJob $job, array $hits, DownloadSequenceType $type): Generator
    {
        return $type === DownloadSequenceType::Mapping ? $this->mappedRegions($job, $hits) : $this->entireSequences($job, $hits);
    }

    /**
     * @param list<BlastHit> $hits
     *
     * @return Generator<int, string>
     */
    private function mappedRegions(BlastJob $job, array $hits): Generator
    {
        $program = $job->getProgram();

        foreach ($hits as $hit) {
            foreach ($hit->getHsps() as $hsp) {
                $query = AlignmentCoordinates::query($program, $hsp->getQueryFrom(), $hsp->getQueryTo(), $hsp->getQueryFrame());
                $subject = AlignmentCoordinates::subject($program, $hsp->getHitFrom(), $hsp->getHitTo(), $hsp->getHitFrame());

                yield \sprintf(
                    ">%s (Subject: %s, %d..%d; Query: %s, %d..%d)\n",
                    $hit->getSubjectDefinition(),
                    $hit->getSubjectAccession() ?? $hit->getSubjectName(),
                    $subject->start,
                    $subject->end,
                    $hit->getQuery()->getName(),
                    $query->start,
                    $query->end,
                ) . $this->wrap(str_replace('-', '', $hsp->getHitSequence()));
            }
        }
    }

    /**
     * @param list<BlastHit> $hits
     *
     * @return Generator<int, string>
     */
    private function entireSequences(BlastJob $job, array $hits): Generator
    {
        /** @var array<string, ?string> $subjects nome => accessione, nell'ordine della selezione */
        $subjects = [];

        foreach ($hits as $hit) {
            $subjects[$hit->getSubjectName()] ??= $hit->getSubjectAccession();
        }

        $databases = $this->registeredDatabases($job);
        $found = $this->fromBlastDatabases($job, $databases, $subjects);
        $subjectFasta = $job->getSubjectFasta();

        if ($subjectFasta !== null && array_diff_key($subjects, $found) !== []) {
            $found += $this->fromFasta(explode("\n", $subjectFasta), array_diff_key($subjects, $found));
        }

        foreach ($databases as $database) {
            $sourceFasta = $database->getSourceFasta();
            $missing = array_diff_key($subjects, $found);

            if ($missing !== [] && $sourceFasta !== null && is_file($this->storage->absolute($sourceFasta))) {
                $found += $this->fromFasta($this->fileLines($this->storage->absolute($sourceFasta)), $missing);
            }
        }

        foreach (array_keys($subjects) as $name) {
            if (isset($found[$name])) {
                yield $found[$name];
            } else {
                $this->logger->warning('Sequence of subject {subject} not found for job {job}.', ['subject' => $name, 'job' => $job->getId()->toRfc4122()]);
            }
        }
    }

    /**
     * @return list<BlastDatabase>
     */
    private function registeredDatabases(BlastJob $job): array
    {
        $databases = [];

        foreach ($job->getDatabases() as $snapshot) {
            $database = $this->databases->find($snapshot['id']);

            if ($database instanceof BlastDatabase) {
                $databases[] = $database;
            }
        }

        return $databases;
    }

    /**
     * Sequenze intere dai volumi BLAST, per accessione (database costruiti con -parse_seqids).
     *
     * @param list<BlastDatabase>    $databases
     * @param array<string, ?string> $subjects
     *
     * @return array<string, string> nome del soggetto => record FASTA
     */
    private function fromBlastDatabases(BlastJob $job, array $databases, array $subjects): array
    {
        $byAccession = [];

        foreach ($subjects as $name => $accession) {
            if ($accession !== null) {
                $byAccession[$this->withoutVersion($accession)] = $name;
            }
        }

        if ($databases === [] || $byAccession === []) {
            return [];
        }

        $directory = $this->workspace->prepare($job->getId());
        $idFile = $this->workspace->write($job->getId(), self::ID_FILE, implode("\n", array_keys($byAccession)) . "\n");

        try {
            // Exit code 1: alcune voci non trovate ("Skipped"); le altre sono comunque nell'output.
            $result = $this->executor->execute(BlastTool::BlastDbCmd->value, [
                $this->locator->tool(BlastTool::BlastDbCmd),
                '-db', implode(' ', array_map($this->storage->volumesOf(...), $databases)),
                '-dbtype', $job->getProgram()->databaseMolType()->value,
                '-entry_batch', $idFile,
                '-outfmt', '%f',
            ], $directory, [0, 1]);
        } catch (BlastExecutionException $blastExecutionException) {
            $this->logger->warning('blastdbcmd could not extract sequences for job {job}: {error}', ['job' => $job->getId()->toRfc4122(), 'error' => $blastExecutionException->getMessage()]);

            return [];
        }

        $found = [];

        $records = preg_split('/^(?=>)/m', $result->output, -1, \PREG_SPLIT_NO_EMPTY);

        foreach ($records === false ? [] : $records as $record) {
            $accession = $this->withoutVersion(SequenceNames::firstToken(substr(explode("\n", $record, 2)[0], 1)));

            if (isset($byAccession[$accession])) {
                $found[$byAccession[$accession]] = rtrim($record, "\n") . "\n";
            }
        }

        return $found;
    }

    /**
     * Sequenze intere da un FASTA (caricato con il job o sorgente di un database), riconoscendo il
     * soggetto dal primo token del defline come il legacy. Il file viene letto riga per riga.
     *
     * @param iterable<string>       $lines
     * @param array<string, ?string> $wanted
     *
     * @return array<string, string>
     */
    private function fromFasta(iterable $lines, array $wanted): array
    {
        $found = [];
        $name = null;
        $header = '';
        $sequence = '';

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if (str_starts_with($line, '>')) {
                if ($name !== null) {
                    $found[$name] = $header . "\n" . $this->wrap($sequence);
                }

                $candidate = SequenceNames::firstToken(substr($line, 1));
                // array_key_exists, non isset: i soggetti senza accessione hanno valore null.
                $name = \array_key_exists($candidate, $wanted) && !isset($found[$candidate]) ? $candidate : null;
                $header = $line;
                $sequence = '';
            } elseif ($name !== null) {
                $sequence .= strtoupper(preg_replace('/[\s\-]+/', '', $line) ?? '');
            }
        }

        if ($name !== null) {
            $found[$name] = $header . "\n" . $this->wrap($sequence);
        }

        return $found;
    }

    /**
     * Righe del file una alla volta: i FASTA sorgente possono essere molto grandi.
     *
     * @return Generator<int, string>
     */
    private function fileLines(string $path): Generator
    {
        if (!is_readable($path)) {
            $this->logger->warning('FASTA file {path} cannot be read.', ['path' => $path]);

            return;
        }

        foreach (new SplFileObject($path) as $line) {
            if (\is_string($line)) {
                yield $line;
            }
        }
    }

    private function wrap(string $sequence): string
    {
        return $sequence === '' ? '' : chunk_split($sequence, self::LINE_WIDTH, "\n");
    }

    private function withoutVersion(string $accession): string
    {
        return preg_replace('/\.\d+$/', '', $accession) ?? $accession;
    }
}
