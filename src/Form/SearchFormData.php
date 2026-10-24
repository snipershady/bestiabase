<?php

declare(strict_types=1);

namespace App\Form;

use App\Blast\Job\BlastSearchRequest;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Blast\Search\SearchParameters;
use App\Entity\BlastDatabase;
use App\Entity\User;
use App\Enum\BlastProgram;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Modello del form di ricerca (porting di viroblast.php + viroblast.js). I campi avanzati
 * esistono sempre nel form; {@see toSearchParameters()} usa solo quelli che il programma
 * accetta, come faceva il JavaScript legacy togliendo i campi dal DOM.
 */
final class SearchFormData
{
    /** Valori del form legacy: blastn 7/11/15, altri programmi 2/3. */
    public const array WORD_SIZES = [BlastProgram::Blastn->value => [7, 11, 15], 'other' => [2, 3]];

    public const array MAX_TARGET_SEQUENCES = [10, 50, 100, 250, 500, 1000, 5000, 10000];

    #[Assert\NotNull]
    public ?BlastProgram $program = BlastProgram::Blastn;

    #[Assert\NotBlank(message: 'Enter a query sequence in FASTA format or as plain residues.')]
    public ?string $query = null;

    /** @var Collection<int, BlastDatabase> EntityType "multiple" lavora con una Collection */
    #[Assert\Count(min: 1, minMessage: 'Choose at least one database.')]
    public Collection $databases;

    #[Assert\NotBlank(groups: ['advanced'])]
    #[Assert\Regex(pattern: '/^\s*(\d*\.?\d+)([eE][+-]?\d+)?\s*$/', message: 'Enter a positive number, e.g. 10, 0.001 or 1e-10.', groups: ['advanced'])]
    public ?string $expect = '10';

    public ?int $wordSize = 11;

    #[Assert\Choice(choices: self::MAX_TARGET_SEQUENCES, groups: ['advanced'])]
    public ?int $maxTargetSequences = SearchParameters::DEFAULT_MAX_TARGET_SEQUENCES;

    public ?NucleotideScoring $nucleotideScoring = NucleotideScoring::Reward2Penalty3;

    public ?ScoringMatrix $matrix = ScoringMatrix::Blosum62;

    /** Coppia "existence,extension", come le option della select legacy. */
    #[Assert\Regex(pattern: '/^\d{1,2},\d{1,2}$/', groups: ['advanced'])]
    public ?string $gapCosts = '5,2';

    public bool $lowComplexityFilter = true;

    public bool $softMasking = true;

    public bool $lowercaseMasking = false;

    public bool $ungapped = false;

    public ?GeneticCode $queryGeneticCode = GeneticCode::Standard;

    public ?GeneticCode $databaseGeneticCode = GeneticCode::Standard;

    public function __construct()
    {
        $this->databases = new ArrayCollection();
    }

    /**
     * Regole che dipendono dal programma; valgono solo per la ricerca avanzata.
     */
    #[Assert\Callback(groups: ['advanced'])]
    public function validateAdvanced(ExecutionContextInterface $context): void
    {
        $program = $this->program ?? BlastProgram::Blastn;
        $allowedWordSizes = self::WORD_SIZES[$program === BlastProgram::Blastn ? $program->value : 'other'];

        if (!\in_array($this->wordSize, $allowedWordSizes, true)) {
            $context->buildViolation('Choose a word size among {{ sizes }} for {{ program }}.')
                ->setParameter('{{ sizes }}', implode(', ', $allowedWordSizes))
                ->setParameter('{{ program }}', $program->value)
                ->atPath('wordSize')
                ->addViolation();
        }

        $scoring = $this->scoring($program);
        $gapCosts = $this->parsedGapCosts();

        if ($program !== BlastProgram::Tblastx && $scoring !== null && $gapCosts instanceof GapCosts && !GapCostPresets::isAllowed($scoring, $gapCosts)) {
            $context->buildViolation('These gap costs are not available for {{ scoring }}.')
                ->setParameter('{{ scoring }}', $scoring->value)
                ->atPath('gapCosts')
                ->addViolation();
        }
    }

    /**
     * Richiesta di ricerca: "basic" (default di BLAST+) o "advanced" (parametri del form).
     */
    public function toSearchRequest(bool $advanced, ?User $user = null): BlastSearchRequest
    {
        return new BlastSearchRequest(
            program: $this->program ?? BlastProgram::Blastn,
            queryFasta: $this->query ?? '',
            databases: array_values($this->databases->toArray()),
            parameters: $advanced ? $this->toSearchParameters() : null,
            user: $user,
        );
    }

    /**
     * Parametri della ricerca avanzata, ridotti alle opzioni valide per il programma.
     */
    public function toSearchParameters(): SearchParameters
    {
        $program = $this->program ?? BlastProgram::Blastn;
        $translatesQuery = \in_array($program, [BlastProgram::Blastx, BlastProgram::Tblastx], true);
        $translatesDatabase = \in_array($program, [BlastProgram::Tblastn, BlastProgram::Tblastx], true);

        return new SearchParameters(
            expect: (float) trim($this->expect ?? ''),
            wordSize: $this->wordSize ?? 0,
            maxTargetSequences: $this->maxTargetSequences ?? SearchParameters::DEFAULT_MAX_TARGET_SEQUENCES,
            nucleotideScoring: $program === BlastProgram::Blastn ? $this->nucleotideScoring : null,
            matrix: $program === BlastProgram::Blastn ? null : $this->matrix,
            gapCosts: $program === BlastProgram::Tblastx ? null : $this->parsedGapCosts(),
            lowComplexityFilter: $this->lowComplexityFilter,
            softMasking: $this->softMasking,
            lowercaseMasking: $this->lowercaseMasking,
            ungapped: $program === BlastProgram::Tblastx ? false : $this->ungapped,
            queryGeneticCode: $translatesQuery ? $this->queryGeneticCode : null,
            databaseGeneticCode: $translatesDatabase ? $this->databaseGeneticCode : null,
        );
    }

    private function scoring(BlastProgram $program): NucleotideScoring|ScoringMatrix|null
    {
        return $program === BlastProgram::Blastn ? $this->nucleotideScoring : $this->matrix;
    }

    private function parsedGapCosts(): ?GapCosts
    {
        if ($this->gapCosts === null || preg_match('/^(\d{1,2}),(\d{1,2})$/', $this->gapCosts, $match) !== 1) {
            return null;
        }

        return new GapCosts((int) $match[1], (int) $match[2]);
    }
}
