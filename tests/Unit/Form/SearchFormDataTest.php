<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Blast\Job\BlastSearchRequest;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\NucleotideScoring;
use App\Blast\Search\ScoringMatrix;
use App\Blast\Search\SearchParameters;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\Form\SearchFormData;
use App\Tests\Support\EntityFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[CoversClass(SearchFormData::class)]
#[UsesClass(BlastSearchRequest::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
final class SearchFormDataTest extends TestCase
{
    /**
     * @return iterable<string, array{BlastProgram, array{bool, bool, bool, bool, bool, bool}}>
     */
    public static function applicableOptions(): iterable
    {
        // [scoring, matrix, gap costs, ungapped, query genetic code, database genetic code]
        yield 'blastn' => [BlastProgram::Blastn, [true, false, true, true, false, false]];
        yield 'blastp' => [BlastProgram::Blastp, [false, true, true, true, false, false]];
        yield 'blastx' => [BlastProgram::Blastx, [false, true, true, true, true, false]];
        yield 'tblastn' => [BlastProgram::Tblastn, [false, true, true, true, false, true]];
        yield 'tblastx' => [BlastProgram::Tblastx, [false, true, false, false, true, true]];
    }

    /**
     * @param array{bool, bool, bool, bool, bool, bool} $expected
     */
    #[DataProvider('applicableOptions')]
    public function testOnlyTheOptionsOfTheProgramAreKept(BlastProgram $program, array $expected): void
    {
        $data = new SearchFormData();
        $data->program = $program;
        $data->expect = '1e-5';
        $data->wordSize = 3;
        $data->ungapped = true;
        $data->queryGeneticCode = GeneticCode::Bacterial;
        $data->databaseGeneticCode = GeneticCode::Bacterial;
        $data->gapCosts = '11,1';

        $parameters = $data->toSearchParameters();

        self::assertEqualsWithDelta(1e-5, $parameters->expect, 1e-12);
        self::assertSame($expected, [
            $parameters->nucleotideScoring instanceof NucleotideScoring,
            $parameters->matrix instanceof ScoringMatrix,
            $parameters->gapCosts instanceof GapCosts,
            $parameters->ungapped,
            $parameters->queryGeneticCode instanceof GeneticCode,
            $parameters->databaseGeneticCode instanceof GeneticCode,
        ]);
    }

    public function testMalformedGapCostsAreIgnored(): void
    {
        $data = new SearchFormData();
        $data->gapCosts = 'eleven';

        self::assertNull($data->toSearchParameters()->gapCosts);
    }

    public function testBasicRequestHasNoParameters(): void
    {
        $data = new SearchFormData();
        $data->query = 'ACGT';

        $database = EntityFactory::persistedDatabase(4);
        $data->databases->add($database);
        $user = EntityFactory::user();

        $request = $data->toSearchRequest(false, $user);

        self::assertNull($request->parameters);
        self::assertSame([$database], $request->databases);
        self::assertSame('ACGT', $request->queryFasta);
        self::assertSame($user, $request->user);
    }

    public function testAdvancedRequestKeepsTheParameters(): void
    {
        $data = new SearchFormData();
        $data->query = 'ACGT';

        $request = $data->toSearchRequest(true);

        self::assertNotNull($request->parameters);
    }

    public function testAdvancedValidationChecksWordSizeAndGapCostsForTheProgram(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $data = new SearchFormData();
        $data->query = 'MKV';
        $data->databases->add(EntityFactory::persistedDatabase(1, 'test_aa_db', MolType::Protein));
        $data->program = BlastProgram::Blastp;
        $data->wordSize = 11;
        $data->matrix = ScoringMatrix::Pam30;
        $data->gapCosts = '11,1';

        $violations = $validator->validate($data, null, ['Default', 'advanced']);

        $messages = [];
        foreach ($violations as $violation) {
            $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        self::assertSame([
            'wordSize' => 'Choose a word size among 2, 3 for blastp.',
            'gapCosts' => 'These gap costs are not available for PAM30.',
        ], $messages);
        self::assertCount(0, $validator->validate($data, null, ['Default']), 'basic searches ignore advanced fields');
    }

    public function testTblastxHasNoGapCostCheck(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $data = new SearchFormData();
        $data->query = 'ACGT';
        $data->databases->add(EntityFactory::persistedDatabase(1));
        $data->program = BlastProgram::Tblastx;
        $data->wordSize = 3;
        $data->gapCosts = '99,99';

        self::assertCount(0, $validator->validate($data, null, ['Default', 'advanced']));
    }
}
