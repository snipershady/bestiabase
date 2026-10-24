<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blast;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Blast\Search\SearchParameters;
use App\Enum\BlastProgram;
use App\Tests\Support\FakeBlastInstallation;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tabella di verità del porting di blast.pl (Appendice A di DEEPSEEK.md).
 */
#[CoversClass(BlastCommandBuilder::class)]
#[CoversClass(InvalidSearchParametersException::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(NucleotideScoring::class)]
final class BlastCommandBuilderTest extends TestCase
{
    private const array IO = ['-db', '/db/a /db/b', '-query', '/job/query.fa', '-out', '/job/output.xml'];

    private FakeBlastInstallation $blast;

    private BlastCommandBuilder $builder;

    #[Override]
    protected function setUp(): void
    {
        $this->blast = new FakeBlastInstallation();
        $this->builder = new BlastCommandBuilder($this->blast->locator());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->blast->remove();
    }

    /**
     * @return iterable<string, array{BlastProgram, list<string>}>
     */
    public static function basicSearches(): iterable
    {
        yield 'blastn' => [BlastProgram::Blastn, ['-task', 'blastn']];
        yield 'blastp' => [BlastProgram::Blastp, ['-task', 'blastp']];
        yield 'blastx' => [BlastProgram::Blastx, []];
        yield 'tblastn' => [BlastProgram::Tblastn, []];
        yield 'tblastx' => [BlastProgram::Tblastx, []];
    }

    /**
     * @param list<string> $task
     */
    #[DataProvider('basicSearches')]
    public function testBasicSearchUsesBlastDefaultsWithFiftyTargets(BlastProgram $program, array $task): void
    {
        self::assertSame(
            [$this->blast->binary($program->value), ...$task, ...self::IO, '-outfmt', '5', '-max_target_seqs', '50'],
            $this->build($program, null),
        );
    }

    /**
     * @return iterable<string, array{BlastProgram, list<string>}>
     */
    public static function advancedDefaults(): iterable
    {
        yield 'blastn' => [BlastProgram::Blastn, ['-task', 'blastn', ...self::IO, '-evalue', '10', '-word_size', '11', '-reward', '2', '-penalty', '-3', '-gapopen', '5', '-gapextend', '2']];
        yield 'blastp' => [BlastProgram::Blastp, ['-task', 'blastp', ...self::IO, '-evalue', '10', '-word_size', '3', '-matrix', 'BLOSUM62', '-gapopen', '11', '-gapextend', '1']];
        yield 'blastx' => [BlastProgram::Blastx, [...self::IO, '-evalue', '10', '-word_size', '3', '-matrix', 'BLOSUM62', '-gapopen', '11', '-gapextend', '1', '-query_gencode', '1']];
        yield 'tblastn' => [BlastProgram::Tblastn, [...self::IO, '-evalue', '10', '-word_size', '3', '-matrix', 'BLOSUM62', '-gapopen', '11', '-gapextend', '1', '-db_gencode', '1']];
        yield 'tblastx' => [BlastProgram::Tblastx, [...self::IO, '-evalue', '10', '-word_size', '3', '-matrix', 'BLOSUM62', '-query_gencode', '1', '-db_gencode', '1']];
    }

    /**
     * Con i default del form non serve alcun flag di filtro o masking: coincidono con BLAST+.
     *
     * @param list<string> $options
     */
    #[DataProvider('advancedDefaults')]
    public function testAdvancedDefaultsOfTheLegacyForm(BlastProgram $program, array $options): void
    {
        self::assertSame(
            [$this->blast->binary($program->value), ...$options, '-outfmt', '5', '-max_target_seqs', '50'],
            $this->build($program, SearchParameters::defaultsFor($program)),
        );
    }

    /**
     * @return iterable<string, array{BlastProgram, bool, bool, list<string>}>
     */
    public static function filterAndMasking(): iterable
    {
        // [programma, filtro bassa complessità, soft masking, flag attesi]
        yield 'blastn filter off' => [BlastProgram::Blastn, false, true, ['-dust', 'no']];
        yield 'blastn soft masking off' => [BlastProgram::Blastn, true, false, ['-soft_masking', 'false']];
        yield 'blastp filter on' => [BlastProgram::Blastp, true, false, ['-seg', 'yes']];
        yield 'blastp filter off' => [BlastProgram::Blastp, false, false, []];
        yield 'blastx filter off' => [BlastProgram::Blastx, false, false, ['-seg', 'no']];
        yield 'blastx soft masking on' => [BlastProgram::Blastx, true, true, ['-soft_masking', 'true']];
        yield 'tblastn filter off' => [BlastProgram::Tblastn, false, false, ['-seg', 'no']];
        yield 'tblastx filter off, soft on' => [BlastProgram::Tblastx, false, true, ['-seg', 'no', '-soft_masking', 'true']];
    }

    /**
     * Logica "inversa" di blast.pl L81–102: si aggiunge un flag solo se diverso dal default BLAST+.
     *
     * @param list<string> $expected
     */
    #[DataProvider('filterAndMasking')]
    public function testFilterAndMaskingOnlyOverrideBlastDefaults(BlastProgram $program, bool $filter, bool $softMasking, array $expected): void
    {
        $parameters = new SearchParameters(10.0, $program === BlastProgram::Blastn ? 11 : 3, 50, lowComplexityFilter: $filter, softMasking: $softMasking);

        self::assertSame($expected, $this->optionsBetween('-word_size', '-outfmt', $this->build($program, $parameters)));
    }

    public function testEveryAdvancedOptionForBlastn(): void
    {
        $parameters = new SearchParameters(
            expect: 1.0E-10,
            wordSize: 7,
            maxTargetSequences: 250,
            nucleotideScoring: NucleotideScoring::Reward1Penalty2,
            gapCosts: new GapCosts(2, 2),
            lowComplexityFilter: false,
            softMasking: false,
            lowercaseMasking: true,
            ungapped: true,
        );

        self::assertSame(
            [
                $this->blast->binary('blastn'), '-task', 'blastn', ...self::IO,
                '-evalue', '1.0e-10', '-word_size', '7', '-reward', '1', '-penalty', '-2', '-gapopen', '2', '-gapextend', '2',
                '-dust', 'no', '-soft_masking', 'false', '-lcase_masking', '-ungapped',
                '-outfmt', '5', '-max_target_seqs', '250',
            ],
            $this->build(BlastProgram::Blastn, $parameters),
        );
    }

    public function testExpectValueIsWrittenWithoutTrailingZeros(): void
    {
        $command = $this->build(BlastProgram::Blastp, new SearchParameters(0.001, 3, 10));

        self::assertSame(['-evalue', '0.001'], \array_slice($command, (int) array_search('-evalue', $command, true), 2));
    }

    public function testGeneticCodesForTranslatedSearches(): void
    {
        $parameters = new SearchParameters(10.0, 3, 50, queryGeneticCode: GeneticCode::Bacterial, databaseGeneticCode: GeneticCode::VertebrateMitochondrial);

        self::assertSame(
            ['-query_gencode', '11', '-db_gencode', '2'],
            $this->optionsBetween('-word_size', '-outfmt', $this->build(BlastProgram::Tblastx, $parameters)),
        );
    }

    /**
     * @return iterable<string, array{BlastProgram, SearchParameters, string}>
     */
    public static function unsupportedOptions(): iterable
    {
        yield 'scoring is blastn only' => [BlastProgram::Blastp, new SearchParameters(10.0, 3, 50, nucleotideScoring: NucleotideScoring::Reward2Penalty3), 'nucleotideScoring'];
        yield 'matrix is not for blastn' => [BlastProgram::Blastn, new SearchParameters(10.0, 11, 50, matrix: ScoringMatrix::Blosum62), 'matrix'];
        yield 'tblastx has no gap costs' => [BlastProgram::Tblastx, new SearchParameters(10.0, 3, 50, gapCosts: new GapCosts(11, 1)), 'gapCosts'];
        yield 'tblastx is always ungapped' => [BlastProgram::Tblastx, new SearchParameters(10.0, 3, 50, ungapped: true), 'ungapped'];
        yield 'query genetic code needs a translated query' => [BlastProgram::Tblastn, new SearchParameters(10.0, 3, 50, queryGeneticCode: GeneticCode::Standard), 'queryGeneticCode'];
        yield 'database genetic code needs a translated database' => [BlastProgram::Blastx, new SearchParameters(10.0, 3, 50, databaseGeneticCode: GeneticCode::Standard), 'databaseGeneticCode'];
    }

    #[DataProvider('unsupportedOptions')]
    public function testOptionsUnsupportedByTheProgramAreRejected(BlastProgram $program, SearchParameters $parameters, string $option): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('Option "%s" is not available for %s.', $option, $program->value));

        $this->build($program, $parameters);
    }

    public function testSupportedParametersPassTheSubmissionCheck(): void
    {
        $this->builder->assertSupported(BlastProgram::Tblastx, SearchParameters::defaultsFor(BlastProgram::Tblastx));

        $this->expectException(InvalidSearchParametersException::class);

        $this->builder->assertSupported(BlastProgram::Tblastx, new SearchParameters(10.0, 3, 50, gapCosts: new GapCosts(11, 1)));
    }

    public function testBlastnWordSizeBelowFourIsRejected(): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessageIsOrContains('Option "wordSize" must be at least 4 for blastn.');

        $this->build(BlastProgram::Blastn, new SearchParameters(10.0, 3, 50));
    }

    public function testProteinWordSizeAboveSevenIsRejected(): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessageIsOrContains('Option "wordSize" must be at most 7 for blastp.');

        $this->build(BlastProgram::Blastp, new SearchParameters(10.0, 8, 50));
    }

    public function testDatabasePathWithWhitespaceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(BlastProgram::Blastn, null, ['/db/my db'], '/q.fa', '/o.xml');
    }

    public function testEmptyDatabasePathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(BlastProgram::Blastn, null, [''], '/q.fa', '/o.xml');
    }

    /**
     * @return non-empty-list<string>
     */
    private function build(BlastProgram $program, ?SearchParameters $parameters): array
    {
        return $this->builder->build($program, $parameters, ['/db/a', '/db/b'], '/job/query.fa', '/job/output.xml');
    }

    /**
     * Opzioni comprese fra il valore di $after e il flag $before (esclusi).
     *
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function optionsBetween(string $after, string $before, array $command): array
    {
        $start = array_search($after, $command, true);
        $end = array_search($before, $command, true);
        self::assertIsInt($start);
        self::assertIsInt($end);

        return \array_slice($command, $start + 2, $end - $start - 2);
    }
}
