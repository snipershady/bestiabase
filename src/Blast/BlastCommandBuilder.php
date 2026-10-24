<?php

declare(strict_types=1);

namespace App\Blast;

use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Blast\Search\SearchParameters;
use App\Enum\BlastProgram;
use InvalidArgumentException;

/**
 * Porting di blast.pl (L40–130): costruisce l'argv di un programma BLAST+.
 *
 * Differenze volute dal legacy: l'output è sempre XML (`-outfmt 5`) con `-max_target_seqs`
 * (con outfmt > 4 BLAST+ ignora `-num_descriptions`/`-num_alignments`), le combinazioni
 * programma/opzione non supportate vengono rifiutate invece di passarle a BLAST e non esiste
 * più il pass-through di opzioni libere (`OTHER_ADVANCED`).
 */
final readonly class BlastCommandBuilder
{
    public const string XML_OUTPUT_FORMAT = '5';

    public function __construct(
        private BlastBinaryLocator $locator,
    ) {
    }

    /**
     * @param SearchParameters|null  $parameters    null = ricerca "basic"
     * @param non-empty-list<string> $databasePaths path dei volumi BLAST (`-db`), già risolti lato server
     *
     * @throws InvalidSearchParametersException se un parametro non è disponibile per il programma
     * @throws InvalidArgumentException         se un path di database contiene spazi
     *
     * @return non-empty-list<string>
     */
    public function build(BlastProgram $program, ?SearchParameters $parameters, array $databasePaths, string $queryFile, string $outputFile): array
    {
        $command = [$this->locator->program($program)];
        if (\in_array($program, [BlastProgram::Blastn, BlastProgram::Blastp], true)) {
            $command[] = '-task';
            $command[] = $program->value;
        }

        $command[] = '-db';
        $command[] = $this->databaseArgument($databasePaths);
        $command[] = '-query';
        $command[] = $queryFile;
        $command[] = '-out';
        $command[] = $outputFile;

        if ($parameters instanceof SearchParameters) {
            array_push($command, ...$this->advancedOptions($program, $parameters));
        }

        $command[] = '-outfmt';
        $command[] = self::XML_OUTPUT_FORMAT;
        $command[] = '-max_target_seqs';
        $command[] = (string) ($parameters instanceof SearchParameters ? $parameters->maxTargetSequences : SearchParameters::DEFAULT_MAX_TARGET_SEQUENCES);

        return $command;
    }

    /**
     * Verifica che i parametri siano accettati dal programma, senza costruire il comando:
     * permette di rifiutare una ricerca al momento dell'invio invece che nel worker.
     *
     * @throws InvalidSearchParametersException
     */
    public function assertSupported(BlastProgram $program, SearchParameters $parameters): void
    {
        $this->advancedOptions($program, $parameters);
    }

    /**
     * @param non-empty-list<string> $databasePaths
     */
    private function databaseArgument(array $databasePaths): string
    {
        foreach ($databasePaths as $path) {
            if ($path === '' || preg_match('/\s/', $path) === 1) {
                // BLAST+ separa i database multipli di -db con spazi.
                throw new InvalidArgumentException(\sprintf('BLAST database path "%s" must be non-empty and contain no whitespace.', $path));
            }
        }

        return implode(' ', $databasePaths);
    }

    /**
     * @return list<string>
     */
    private function advancedOptions(BlastProgram $program, SearchParameters $parameters): array
    {
        $options = ['-evalue', $this->formatNumber($parameters->expect), '-word_size', (string) $this->wordSize($program, $parameters->wordSize)];

        if ($parameters->nucleotideScoring instanceof NucleotideScoring) {
            $this->requirePrograms('nucleotideScoring', $program, BlastProgram::Blastn);
            $options[] = '-reward';
            $options[] = (string) $parameters->nucleotideScoring->reward();
            $options[] = '-penalty';
            $options[] = (string) $parameters->nucleotideScoring->penalty();
        }

        if ($parameters->matrix instanceof ScoringMatrix) {
            $this->requirePrograms('matrix', $program, BlastProgram::Blastp, BlastProgram::Blastx, BlastProgram::Tblastn, BlastProgram::Tblastx);
            $options[] = '-matrix';
            $options[] = $parameters->matrix->value;
        }

        if ($parameters->gapCosts instanceof GapCosts) {
            $this->requirePrograms('gapCosts', $program, BlastProgram::Blastn, BlastProgram::Blastp, BlastProgram::Blastx, BlastProgram::Tblastn);
            $options[] = '-gapopen';
            $options[] = (string) $parameters->gapCosts->existence;
            $options[] = '-gapextend';
            $options[] = (string) $parameters->gapCosts->extension;
        }

        array_push($options, ...$this->lowComplexityFilter($program, $parameters->lowComplexityFilter));
        array_push($options, ...$this->softMasking($program, $parameters->softMasking));

        if ($parameters->lowercaseMasking) {
            $options[] = '-lcase_masking';
        }

        if ($parameters->ungapped) {
            $this->requirePrograms('ungapped', $program, BlastProgram::Blastn, BlastProgram::Blastp, BlastProgram::Blastx, BlastProgram::Tblastn);
            $options[] = '-ungapped';
        }

        if ($parameters->queryGeneticCode instanceof GeneticCode) {
            $this->requirePrograms('queryGeneticCode', $program, BlastProgram::Blastx, BlastProgram::Tblastx);
            $options[] = '-query_gencode';
            $options[] = (string) $parameters->queryGeneticCode->value;
        }

        if ($parameters->databaseGeneticCode instanceof GeneticCode) {
            $this->requirePrograms('databaseGeneticCode', $program, BlastProgram::Tblastn, BlastProgram::Tblastx);
            $options[] = '-db_gencode';
            $options[] = (string) $parameters->databaseGeneticCode->value;
        }

        return $options;
    }

    /**
     * Default di BLAST+: dust attivo per blastn, seg attivo tranne che per blastp.
     * Si aggiunge un flag solo quando la scelta dell'utente differisce dal default (blast.pl L81–93).
     *
     * @return list<string>
     */
    private function lowComplexityFilter(BlastProgram $program, bool $enabled): array
    {
        return match (true) {
            !$enabled && $program === BlastProgram::Blastn => ['-dust', 'no'],
            !$enabled && $program !== BlastProgram::Blastp => ['-seg', 'no'],
            $enabled && $program === BlastProgram::Blastp => ['-seg', 'yes'],
            default => [],
        };
    }

    /**
     * Default di BLAST+: soft masking attivo solo per blastn (blast.pl L94–102).
     *
     * @return list<string>
     */
    private function softMasking(BlastProgram $program, bool $enabled): array
    {
        return match (true) {
            !$enabled && $program === BlastProgram::Blastn => ['-soft_masking', 'false'],
            $enabled && $program !== BlastProgram::Blastn => ['-soft_masking', 'true'],
            default => [],
        };
    }

    private function wordSize(BlastProgram $program, int $wordSize): int
    {
        if ($program === BlastProgram::Blastn && $wordSize < 4) {
            throw InvalidSearchParametersException::outOfRange('wordSize', 'at least 4 for blastn');
        }

        if ($program !== BlastProgram::Blastn && $wordSize > 7) {
            throw InvalidSearchParametersException::outOfRange('wordSize', 'at most 7 for ' . $program->value);
        }

        return $wordSize;
    }

    private function requirePrograms(string $option, BlastProgram $program, BlastProgram ...$supported): void
    {
        if (!\in_array($program, $supported, true)) {
            throw InvalidSearchParametersException::unsupportedOption($option, $program);
        }
    }

    private function formatNumber(float $value): string
    {
        return \sprintf('%.12g', $value);
    }
}
