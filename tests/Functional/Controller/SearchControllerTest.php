<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\SearchController;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\MolType;
use App\EventSubscriber\NotFoundSubscriber;
use App\Exception\IncompatibleProgramException;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastJobRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\Exception\InvalidFastaException;
use App\Service\Blast\Exception\InvalidSearchParametersException;
use App\Service\Blast\Fasta\FastaDocument;
use App\Service\Blast\Fasta\FastaNormalizer;
use App\Service\Blast\Fasta\FastaRecord;
use App\Service\Blast\Job\BlastJobFactory;
use App\Service\Blast\Job\BlastJobSubmitter;
use App\Service\Blast\Job\BlastSearchRequest;
use App\Service\Blast\Search\GapCostPresets;
use App\Service\Blast\Search\GapCosts;
use App\Service\Blast\Search\GeneticCode;
use App\Service\Blast\Search\SearchParameters;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Service\Message\RunBlastJob;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Service\SecurityCheck\SecurityCheckGate;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(SearchController::class)]
#[CoversClass(SearchFormType::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(InvalidFastaException::class)]
#[UsesClass(FastaDocument::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(FastaRecord::class)]
#[UsesClass(BlastJobFactory::class)]
#[UsesClass(BlastJobSubmitter::class)]
#[UsesClass(BlastSearchRequest::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GeneticCode::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(IncompatibleProgramException::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(RunBlastJob::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(InvalidSearchParametersException::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityCheckGate::class)]
#[UsesClass(User::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
final class SearchControllerTest extends FunctionalTestCase
{
    private BlastDatabase $nucleotide;

    private BlastDatabase $protein;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->nucleotide = $this->database('test_na_db', MolType::Nucleotide);
        $this->protein = $this->database('test_aa_db', MolType::Protein);
        $inactive = $this->database('retired_db', MolType::Nucleotide);
        $inactive->deactivate();

        // Le ricerche dal web sono riservate agli utenti abilitati.
        $user = EntityFactory::user('searcher@example.org', 'searcher');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
    }

    public function testFormListsActiveDatabasesWithTheirPrograms(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'BLAST search');
        $options = $crawler->filter('select[name="search_form[databases][]"] option');
        self::assertSame(['Title of test_aa_db', 'Title of test_na_db'], $options->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame('blastn tblastn tblastx', $options->eq(1)->attr('data-programs'));
        self::assertCount(0, $crawler->filter('input[type="file"]'), 'no file upload from the web');

        $config = json_decode((string) $crawler->filter('form')->attr('data-search-form-programs-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        self::assertIsArray($config['blastn'] ?? null);
        self::assertSame(['nucleotideScoring', 'gapCosts', 'ungapped'], $config['blastn']['fields'] ?? null);
    }

    public function testAnonymousVisitorsWithoutTheSecurityCheckAreSentToIt(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/security-check');
    }

    public function testBasicSearchIsQueuedAndRedirectsToTheJob(): void
    {
        $this->submit('basic', ['program' => 'blastn', 'query' => "junk\r\n>q1 first\r\nACGTACGT\r\n", 'databases' => [(string) $this->nucleotide->getId()]]);

        $job = $this->lastJob();
        self::assertResponseRedirects('/job/' . $job->getId()->toRfc4122());
        self::assertSame('basic', $job->getSearchType()->value);
        self::assertSame(">q1 first\nACGTACGT\n", $job->getQueryFasta());
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(RunBlastJob::class, $sent[0]->getMessage());
    }

    public function testAdvancedSearchKeepsOnlyTheOptionsOfTheProgram(): void
    {
        $this->submit('advanced', [
            'program' => 'blastp',
            'query' => 'MKVLAAW',
            'databases' => [(string) $this->protein->getId()],
            'expect' => '1e-3',
            'wordSize' => '2',
            'maxTargetSequences' => '100',
            'nucleotideScoring' => '1,-1',
            'matrix' => 'PAM30',
            'gapCosts' => '9,1',
            'lowComplexityFilter' => '1',
        ]);

        self::assertResponseRedirects();
        $parameters = $this->lastJob()->getParameters();
        self::assertSame([0.001, 2, 100, null, 'PAM30', 9, 1, true, false, null], [
            $parameters['expect'], $parameters['wordSize'], $parameters['maxTargetSequences'], $parameters['nucleotideScoring'],
            $parameters['matrix'], $parameters['gapExistence'], $parameters['gapExtension'], $parameters['lowComplexityFilter'],
            $parameters['softMasking'], $parameters['queryGeneticCode'],
        ]);
    }

    public function testAdvancedOptionsAreValidatedForTheProgram(): void
    {
        $this->submit('advanced', [
            'program' => 'blastn',
            'query' => 'ACGT',
            'databases' => [(string) $this->nucleotide->getId()],
            'expect' => 'ten',
            'wordSize' => '3',
            'maxTargetSequences' => '50',
            'nucleotideScoring' => '4,-5',
            'gapCosts' => '5,2',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#search_form_expect_error1', 'Enter a positive number');
        self::assertSelectorTextContains('#search_form_wordSize_error1', 'Choose a word size among 7, 11, 15 for blastn.');
        self::assertSelectorTextContains('#search_form_gapCosts_error1', 'These gap costs are not available for 4,-5.');
        self::assertSame([], $this->transport()->getSent());
    }

    public function testExpectMustBePositive(): void
    {
        // "0" passa la regex del campo ma non è una soglia valida per BLAST.
        $this->submit('advanced', ['program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $this->nucleotide->getId()], 'expect' => '0', 'wordSize' => '11', 'maxTargetSequences' => '50', 'nucleotideScoring' => '2,-3', 'gapCosts' => '5,2']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Option "expect" must be a positive number.');
    }

    public function testBasicSearchIgnoresTheAdvancedFields(): void
    {
        $this->submit('basic', ['program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $this->nucleotide->getId()], 'expect' => 'not used']);

        self::assertResponseRedirects();
        self::assertSame([], $this->lastJob()->getParameters());
    }

    public function testMissingQueryAndDatabaseAreReported(): void
    {
        $this->submit('basic', ['program' => 'blastn', 'query' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#search_form_query_error1', 'Enter a query sequence');
        self::assertSelectorTextContains('#search_form_databases_error1', 'Choose at least one database.');
    }

    public function testDomainErrorsAreShownOnTheRightField(): void
    {
        $this->submit('basic', ['program' => 'blastn', 'query' => ">p\nMKVLEFQ", 'databases' => [(string) $this->nucleotide->getId()]]);
        self::assertResponseStatusCodeSame(422);
        // M, K e V sono codici IUPAC nucleotidici (ambiguità): sono validi.
        self::assertSelectorTextContains('#search_form_query_error1', 'contains invalid characters: LEFQ');

        $this->submit('basic', ['program' => 'blastp', 'query' => 'MKV', 'databases' => [(string) $this->nucleotide->getId()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#search_form_databases_error1', 'cannot use a nucl database');

        $this->submit('advanced', ['program' => 'tblastx', 'query' => 'ACGT', 'databases' => [(string) $this->nucleotide->getId()], 'expect' => '10', 'wordSize' => '3', 'maxTargetSequences' => '50', 'matrix' => 'BLOSUM62', 'ungapped' => '1', 'gapCosts' => '11,1']);
        self::assertResponseRedirects('', null, 'ungapped is dropped for tblastx instead of failing');
    }

    public function testInactiveDatabaseCannotBeSubmitted(): void
    {
        $retired = $this->entityManager->getRepository(BlastDatabase::class)->findOneBy(['code' => 'retired_db']);
        self::assertInstanceOf(BlastDatabase::class, $retired);

        $this->submit('basic', ['program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $retired->getId()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->transport()->getSent());
    }

    public function testQueryLargerThanTheLimitIsRejectedInBytes(): void
    {
        // 700 caratteri "é" = 1400 byte + header: oltre i 2000 byte di BLAST_QUERY_MAX_BYTES in test.
        $this->submit('basic', ['program' => 'blastn', 'query' => '>' . str_repeat('é', 700) . "\n" . str_repeat('A', 700), 'databases' => [(string) $this->nucleotide->getId()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#search_form_query_error1', 'The query exceeds 0 MB');
    }

    public function testSearchesAreRateLimitedPerAddress(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->submit('basic', ['program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $this->nucleotide->getId()]]);
            self::assertResponseRedirects();
        }

        $this->submit('basic', ['program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $this->nucleotide->getId()]]);

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.alert-danger, .invalid-feedback, form', 'Too many searches from your address.');
        // Il transport in memoria si azzera a ogni richiesta: si contano i job salvati.
        self::assertSame(3, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_job'));
    }

    public function testRequestFromAnotherOriginIsRejected(): void
    {
        $this->client->request('POST', '/', ['search_form' => [
            '_token' => 'csrf-token',
            'program' => 'blastn',
            'query' => 'ACGT',
            'databases' => [(string) $this->nucleotide->getId()],
            'basic' => '',
        ]], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->transport()->getSent());
    }

    /**
     * @param array<string, string|list<string>> $fields
     */
    private function submit(string $button, array $fields): void
    {
        $this->client->request('POST', '/', ['search_form' => [...$fields, '_token' => 'csrf-token', $button => '']], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * @param non-empty-string $code
     */
    private function database(string $code, MolType $molType): BlastDatabase
    {
        $database = EntityFactory::database($code, $molType);
        $database->enableAllCompatiblePrograms();

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        return $database;
    }

    private function lastJob(): BlastJob
    {
        $this->entityManager->clear();
        $job = $this->entityManager->getRepository(BlastJob::class)->findOneBy([], ['createdAt' => 'DESC']);
        self::assertInstanceOf(BlastJob::class, $job);

        return $job;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
