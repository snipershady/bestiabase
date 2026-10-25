<?php

declare(strict_types=1);

namespace App\Service\Blast\Search;

use App\Enum\BlastProgram;
use App\Service\Blast\Exception\InvalidSearchParametersException;

/**
 * Parametri di una ricerca "advanced" (Appendice A di DEEPSEEK.md). La ricerca "basic" non
 * ha parametri: usa i default di BLAST+ con 50 target, come il legacy.
 */
final readonly class SearchParameters
{
    public const int DEFAULT_MAX_TARGET_SEQUENCES = 50;

    /**
     * @throws InvalidSearchParametersException se un valore numerico è fuori intervallo
     */
    public function __construct(
        public float $expect,
        public int $wordSize,
        public int $maxTargetSequences,
        public ?NucleotideScoring $nucleotideScoring = null,
        public ?ScoringMatrix $matrix = null,
        public ?GapCosts $gapCosts = null,
        public bool $lowComplexityFilter = true,
        public bool $softMasking = false,
        public bool $lowercaseMasking = false,
        public bool $ungapped = false,
        public ?GeneticCode $queryGeneticCode = null,
        public ?GeneticCode $databaseGeneticCode = null,
    ) {
        if (!is_finite($expect) || $expect <= 0) {
            throw InvalidSearchParametersException::outOfRange('expect', 'a positive number');
        }

        if ($wordSize < 2) {
            throw InvalidSearchParametersException::outOfRange('wordSize', 'at least 2');
        }

        if ($maxTargetSequences < 1) {
            throw InvalidSearchParametersException::outOfRange('maxTargetSequences', 'at least 1');
        }
    }

    /**
     * Valori preselezionati dal form avanzato legacy per il programma.
     */
    public static function defaultsFor(BlastProgram $program): self
    {
        $nucleotideScoring = $program === BlastProgram::Blastn ? NucleotideScoring::Reward2Penalty3 : null;
        $matrix = $program === BlastProgram::Blastn ? null : ScoringMatrix::Blosum62;
        $translatesQuery = \in_array($program, [BlastProgram::Blastx, BlastProgram::Tblastx], true);
        $translatesDatabase = \in_array($program, [BlastProgram::Tblastn, BlastProgram::Tblastx], true);

        return new self(
            expect: 10.0,
            wordSize: $program === BlastProgram::Blastn ? 11 : 3,
            maxTargetSequences: self::DEFAULT_MAX_TARGET_SEQUENCES,
            nucleotideScoring: $nucleotideScoring,
            matrix: $matrix,
            gapCosts: $program === BlastProgram::Tblastx ? null : GapCostPresets::defaultFor($nucleotideScoring ?? $matrix ?? ScoringMatrix::Blosum62),
            lowComplexityFilter: $program !== BlastProgram::Blastp,
            softMasking: $program === BlastProgram::Blastn,
            queryGeneticCode: $translatesQuery ? GeneticCode::Standard : null,
            databaseGeneticCode: $translatesDatabase ? GeneticCode::Standard : null,
        );
    }
}
