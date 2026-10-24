<?php

declare(strict_types=1);

namespace App\Blast\Result;

use App\Entity\BlastJob;
use App\Enum\BlastProgram;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * Esporta i risultati di un job nel formato tabulare di BLAST (`-outfmt 6`, o 7 con i commenti),
 * colonne di default: qaccver saccver pident length mismatch gapopen qstart qend sstart send
 * evalue bitscore. Le righe vengono dal database, una per HSP, e sono scritte in streaming.
 */
final readonly class TabularExporter
{
    public const string FIELDS = 'query acc.ver, subject acc.ver, % identity, alignment length, mismatches, gap opens, q. start, q. end, s. start, s. end, evalue, bit score';

    public function __construct(
        private Connection $connection,
        private EffectivePrimitiveTypeIdentifierServiceInterface $input,
    ) {
    }

    /**
     * @param callable(string): void $write riceve il testo da scrivere (una o più righe)
     */
    public function export(BlastJob $job, bool $withComments, callable $write): void
    {
        $program = $job->getProgram();
        $rowsByQuery = $this->rowsByQuery($job);
        $queries = $this->connection->fetchAllAssociative(
            'SELECT id, name, definition FROM blast_query WHERE job_id = :job ORDER BY position',
            ['job' => $job->getId()->toBinary()],
            ['job' => ParameterType::BINARY],
        );

        foreach ($queries as $query) {
            $rows = $rowsByQuery[$this->input->getIntValueFromArray('id', $query)] ?? [];

            if ($withComments) {
                $write($this->header($job, $query, \count($rows)));
            }

            foreach ($rows as $row) {
                $write($this->line($program, $this->input->getStringValueFromArray('name', $query, forceString: true), $row));
            }
        }

        if ($withComments) {
            $write(\sprintf("# BLAST processed %d queries\n", \count($queries)));
        }
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function rowsByQuery(BlastJob $job): array
    {
        $rows = $this->connection->iterateAssociative(
            <<<'SQL'
                SELECT h.query_id, h.subject_name, h.subject_accession, s.identity, s.align_length, s.gaps,
                       s.gap_opens, s.query_from, s.query_to, s.query_frame, s.hit_from, s.hit_to, s.hit_frame,
                       s.e_value, s.bit_score
                FROM blast_hit h
                INNER JOIN blast_hsp s ON s.hit_id = h.id
                WHERE h.job_id = :job
                ORDER BY h.query_id, h.hit_rank, s.hsp_rank
                SQL,
            ['job' => $job->getId()->toBinary()],
            ['job' => ParameterType::BINARY],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$this->input->getIntValueFromArray('query_id', $row)][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function header(BlastJob $job, array $query, int $hits): string
    {
        $definition = $this->input->getStringValueFromArray('definition', $query, forceString: true);
        $lines = [
            \sprintf('# %s %s', strtoupper($job->getProgram()->value), $job->getBlastVersion() ?? ''),
            '# Query: ' . ($definition === '' ? $this->input->getStringValueFromArray('name', $query, forceString: true) : $definition),
            '# Database: ' . implode(' ', array_map(static fn (array $database): string => $database['code'], $job->getDatabases())),
        ];

        if ($hits > 0) {
            $lines[] = '# Fields: ' . self::FIELDS;
        }

        $lines[] = \sprintf('# %d hits found', $hits);

        return implode("\n", array_map(rtrim(...), $lines)) . "\n";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function line(BlastProgram $program, string $queryName, array $row): string
    {
        $int = fn (string $column): int => $this->input->getIntValueFromArray($column, $row);
        $identity = $int('identity');
        $length = $int('align_length');
        $query = AlignmentCoordinates::query($program, $int('query_from'), $int('query_to'), $int('query_frame'));
        $subject = AlignmentCoordinates::subject($program, $int('hit_from'), $int('hit_to'), $int('hit_frame'));
        $accession = $this->input->getStringValueFromArray('subject_accession', $row, forceString: true);

        return implode("\t", [
            $queryName,
            $accession === '' ? $this->input->getStringValueFromArray('subject_name', $row, forceString: true) : $accession,
            \sprintf('%.3f', $length === 0 ? 0.0 : $identity * 100 / $length),
            $length,
            $length - $identity - $int('gaps'),
            $int('gap_opens'),
            $query->start,
            $query->end,
            $subject->start,
            $subject->end,
            BlastNumberFormat::tabularEValue($this->input->getStringValueFromArray('e_value', $row, forceString: true)),
            BlastNumberFormat::bitScore($this->input->getFloatValueFromArray('bit_score', $row)),
        ]) . "\n";
    }
}
