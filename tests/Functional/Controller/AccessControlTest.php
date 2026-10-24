<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Blast\BlastBinaryLocator;
use App\Blast\BlastCommandBuilder;
use App\Blast\Fasta\FastaDocument;
use App\Blast\Fasta\FastaNormalizer;
use App\Blast\Fasta\FastaRecord;
use App\Blast\Job\BlastJobFactory;
use App\Blast\Job\BlastJobSubmitter;
use App\Blast\Job\BlastSearchRequest;
use App\Blast\Job\JobStatusView;
use App\Blast\JobWorkspace;
use App\Blast\Result\HitFilter;
use App\Blast\Result\TabularExporter;
use App\Blast\Search\GapCostPresets;
use App\Blast\Search\GapCosts;
use App\Blast\Search\GeneticCode;
use App\Blast\Search\SearchParameters;
use App\Blast\Search\SearchParametersNormalizer;
use App\Controller\JobController;
use App\Controller\MyJobsController;
use App\Controller\SearchController;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastJob;
use App\Entity\User;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Message\RunBlastJob;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastHitRepository;
use App\Repository\BlastJobRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Database pubblici e riservati visti da anonimi, utenti autorizzati e amministratori.
 */
#[CoversClass(MyJobsController::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(FastaDocument::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(FastaRecord::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(BlastJobFactory::class)]
#[UsesClass(BlastJobSubmitter::class)]
#[UsesClass(BlastSearchRequest::class)]
#[UsesClass(JobStatusView::class)]
#[UsesClass(HitFilter::class)]
#[UsesClass(TabularExporter::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GeneticCode::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(JobController::class)]
#[UsesClass(SearchController::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(User::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(JobStatus::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(RunBlastJob::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastHitRepository::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
final class AccessControlTest extends FunctionalTestCase
{
    private User $member;

    private User $admin;

    private BlastDatabase $restricted;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->member = EntityFactory::user('member@example.org', 'member');
        $this->admin = EntityFactory::user('admin@example.org', 'admin');
        $this->admin->grantRole(User::ROLE_ADMIN);

        $public = EntityFactory::database('human_db');
        $public->rename('Human');
        $public->enableAllCompatiblePrograms();

        $this->restricted = EntityFactory::database('mouse_db');
        $this->restricted->rename('Mouse');
        $this->restricted->enableAllCompatiblePrograms();
        $this->restricted->restrict();
        $this->restricted->authorize($this->member);

        foreach ([$this->member, $this->admin, $public, $this->restricted] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function testSearchFormListsOnlyTheDatabasesOfTheVisitor(): void
    {
        self::assertSame(['Human'], $this->databaseOptions());

        $this->client->loginUser($this->member);
        self::assertSame(['Human', 'Mouse'], $this->databaseOptions());

        $this->client->loginUser($this->persisted(EntityFactory::user('stranger@example.org', 'stranger')));
        self::assertSame(['Human'], $this->databaseOptions());

        $this->client->loginUser($this->admin);
        self::assertSame(['Human', 'Mouse'], $this->databaseOptions());
    }

    public function testRestrictedDatabaseCannotBeForcedIntoASearch(): void
    {
        $this->client->request('POST', '/', ['search_form' => ['_token' => 'csrf-token', 'program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $this->restricted->getId()], 'basic' => '']], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_job'));
    }

    public function testAuthorizedMembersOwnTheirSearches(): void
    {
        $this->client->loginUser($this->member);

        $this->client->request('POST', '/', ['search_form' => ['_token' => 'csrf-token', 'program' => 'blastn', 'query' => 'ACGT', 'databases' => [(string) $this->restricted->getId()], 'basic' => '']], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $job = $this->entityManager->getRepository(BlastJob::class)->findOneBy([]) ?? self::fail('job not saved');
        self::assertSame('member@example.org', $job->getUser()?->getEmail());
    }

    public function testJobPagesFollowTheOwnership(): void
    {
        $job = EntityFactory::jobOn($this->restricted, null, $this->member);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $url = '/job/' . $job->getId()->toRfc4122();

        $this->client->request('GET', $url);
        self::assertResponseRedirects('/login', null, 'anonymous visitors are asked to sign in');

        $this->client->loginUser($this->persisted(EntityFactory::user('other@example.org', 'other')));
        $this->client->request('GET', $url . '/status');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->member);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', $url . '/hits.tsv');
        self::assertResponseStatusCodeSame(404, 'administrators get past the access check (the job has no results yet)');
    }

    public function testMySearchesListsOnlyTheJobsOfTheUser(): void
    {
        $mine = EntityFactory::jobOn($this->restricted, null, $this->member);
        $theirs = EntityFactory::jobOn($this->restricted, null, $this->admin);
        $this->entityManager->persist($mine);
        $this->entityManager->persist($theirs);
        $this->entityManager->flush();

        $this->client->request('GET', '/my-jobs');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->member);
        $crawler = $this->client->request('GET', '/my-jobs');

        self::assertResponseIsSuccessful();
        self::assertSame(['/job/' . $mine->getId()->toRfc4122()], $crawler->filter('table tbody a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSelectorNotExists('a:contains("Newer")');
    }

    /**
     * @return list<string>
     */
    private function databaseOptions(): array
    {
        $crawler = $this->client->request('GET', '/');

        return $crawler->filter('select[name="search_form[databases][]"] option')->each(static fn (Crawler $option): string => $option->text());
    }

    private function persisted(User $user): User
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
