<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\JobController;
use App\Entity\BlastDatabase;
use App\Entity\BlastDatabaseProgram;
use App\Entity\BlastDownload;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\BlastProgram;
use App\Enum\JobStatus;
use App\Enum\SearchType;
use App\EventSubscriber\NotFoundSubscriber;
use App\Repository\BlastDatabaseRepository;
use App\Repository\BlastHitRepository;
use App\Repository\BlastJobRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastProcessExecutor;
use App\Service\Blast\Database\BlastDatabaseStorage;
use App\Service\Blast\Job\JobStatusView;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Result\AlignmentBlock;
use App\Service\Blast\Result\AlignmentCoordinates;
use App\Service\Blast\Result\AlignmentRow;
use App\Service\Blast\Result\BlastNumberFormat;
use App\Service\Blast\Result\DownloadRequest;
use App\Service\Blast\Result\HitFilter;
use App\Service\Blast\Result\HitRow;
use App\Service\Blast\Result\HspAlignment;
use App\Service\Blast\Result\PairwiseRenderer;
use App\Service\Blast\Result\SequenceExtractor;
use App\Service\Blast\Result\TabularExporter;
use App\Service\Blast\SequenceNames;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\BlastExtension;
use App\Twig\SecurityExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JobController::class)]
#[CoversClass(DownloadRequest::class)]
#[CoversClass(JobStatusView::class)]
#[UsesClass(BlastJob::class)]
#[UsesClass(JobStatus::class)]
#[UsesClass(BlastJobRepository::class)]
#[UsesClass(JobWorkspace::class)]
#[UsesClass(AlignmentBlock::class)]
#[UsesClass(AlignmentCoordinates::class)]
#[UsesClass(AlignmentRow::class)]
#[UsesClass(BlastNumberFormat::class)]
#[UsesClass(HitFilter::class)]
#[UsesClass(HitRow::class)]
#[UsesClass(HspAlignment::class)]
#[UsesClass(PairwiseRenderer::class)]
#[UsesClass(TabularExporter::class)]
#[UsesClass(BlastHit::class)]
#[UsesClass(BlastHsp::class)]
#[UsesClass(BlastQuery::class)]
#[UsesClass(BlastProgram::class)]
#[UsesClass(BlastHitRepository::class)]
#[UsesClass(BlastExtension::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastProcessExecutor::class)]
#[UsesClass(BlastDatabaseStorage::class)]
#[UsesClass(SequenceExtractor::class)]
#[UsesClass(SequenceNames::class)]
#[UsesClass(BlastDownload::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(BlastDatabase::class)]
#[UsesClass(BlastDatabaseProgram::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SecurityCheckCookie::class)]
final class JobControllerTest extends FunctionalTestCase
{
    public function testStatusOfARunningJob(): void
    {
        $job = $this->savedJob();
        $job->start(EntityFactory::now());

        $this->entityManager->flush();

        $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '/status');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([
            'id' => $job->getId()->toRfc4122(),
            'status' => 'running',
            'finished' => false,
            'program' => 'blastn',
            'numQueries' => null,
            'numHits' => 0,
            'error' => null,
            'blastVersion' => null,
            'createdAt' => '2026-10-24T12:00:00+00:00',
            'startedAt' => '2026-10-24T12:00:00+00:00',
            'finishedAt' => null,
        ], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testFailedJobExposesItsError(): void
    {
        $job = $this->savedJob();
        $job->fail(EntityFactory::now(), 'makeblastdb exited with code 1');

        $this->entityManager->flush();

        $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '/status');

        $status = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertSame(['failed', true, 'makeblastdb exited with code 1'], [$status['status'] ?? null, $status['finished'] ?? null, $status['error'] ?? null]);
    }

    public function testJobPageShowsTheStatusAndPollsUntilFinished(): void
    {
        $job = $this->savedJob();

        $crawler = $this->client->request('GET', '/job/' . $job->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'blastn search');
        self::assertSelectorTextContains('[data-job-status-target="status"]', 'pending');
        self::assertSame('/job/' . $job->getId()->toRfc4122() . '/status', $crawler->filter('[data-controller="job-status"]')->attr('data-job-status-url-value'));
        self::assertSame('false', $crawler->filter('[data-controller="job-status"]')->attr('data-job-status-finished-value'));
    }

    public function testFailedJobPageShowsTheError(): void
    {
        $job = $this->savedJob();
        $job->fail(EntityFactory::now(), 'blastn exited with code 2');

        $this->entityManager->flush();

        $this->client->request('GET', '/job/' . $job->getId()->toRfc4122());

        self::assertSelectorTextContains('.alert-danger', 'blastn exited with code 2');
    }

    public function testUnknownOrMalformedIdsAreNotFound(): void
    {
        $this->client->request('GET', '/job/' . Uuid::v7()->toRfc4122() . '/status');
        self::assertResponseRedirects('/nopainnogain');

        $this->client->request('GET', '/job/../../etc/passwd/status');
        self::assertResponseRedirects('/nopainnogain');

        $this->client->request('GET', '/job/169999999912/status');
        self::assertResponseRedirects('/nopainnogain', null, 'legacy numeric job ids are not accepted');
    }

    public function testFinishedJobShowsTheResultsTableWithNcbiLinks(): void
    {
        [$job, $hit] = $this->finishedJob();

        $crawler = $this->client->request('GET', '/job/' . $job->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $cells = $crawler->filter('table tbody tr')->first()->filter('td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['', 'q1', 'gi|1786181|gb|AE000111|ECAE000111', '632', '4/4 (350)', '100', '0.0'], $cells);
        self::assertSame('https://www.ncbi.nlm.nih.gov/nuccore/AE000111?report=genbank', $crawler->filter('table tbody td a[target="_blank"]')->attr('href'));
        self::assertSame('/job/' . $job->getId()->toRfc4122() . '/hit/' . $hit->getId(), $crawler->filter('table tbody td.text-end a')->attr('href'));
        self::assertSelectorExists('a[href$="/hits.tsv"]');
        self::assertSelectorNotExists('a[href$="/output.xml"]', 'no XML file in the workspace');
        self::assertSelectorNotExists('.pagination');
    }

    public function testMappedRegionsAreDownloadedAndTheDownloadIsRecorded(): void
    {
        [$job, $hit] = $this->finishedJob();

        $this->download($job, ['type' => 'mapping', 'hits' => [(string) $hit->getId()]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/x-fasta; charset=UTF-8');
        self::assertStringContainsString('-mapping.fasta', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(">gi|1786181|gb|AE000111|ECAE000111 Escherichia coli section 1 (Subject: AE000111, 1..4; Query: q1, 1..4)\nACGT\n", $this->client->getInternalResponse()->getContent());
        self::assertSame(['mapping', 1], [
            $this->entityManager->getConnection()->fetchOne('SELECT sequence_type FROM blast_download'),
            $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_download_item'),
        ]);
    }

    public function testEntireSequencesOfAnUploadedSubject(): void
    {
        $job = new BlastJob(Uuid::v7(), BlastProgram::Blastn, SearchType::Basic, [], [], ">q1\nACGT\n", EntityFactory::now(), ">patient_12 clone 4\nACGTACGT\n");
        $job->start(EntityFactory::now());
        $job->finish(EntityFactory::now(), 1, 1);

        $query = EntityFactory::query($job);
        $hit = new BlastHit($job, $query, 1, 'patient_12', 8, null, 'patient_12 clone 4');
        $this->entityManager->persist($job);
        $this->entityManager->persist($query);
        $this->entityManager->persist($hit);
        $this->entityManager->flush();

        $this->download($job, ['type' => 'entire', 'hits' => [(string) $hit->getId()]]);

        self::assertResponseIsSuccessful();
        self::assertSame(">patient_12 clone 4\nACGTACGT\n", $this->client->getInternalResponse()->getContent());
    }

    public function testInvalidDownloadsAreRejected(): void
    {
        [$job, $hit] = $this->finishedJob();
        [, $foreignHit] = $this->finishedJob();

        $this->download($job, ['type' => 'entire', 'hits' => [(string) $hit->getId(), (string) $foreignHit->getId()]]);
        self::assertResponseRedirects('/nopainnogain', null, 'hits of another job');

        $this->download($job, ['type' => 'entire']);
        self::assertResponseStatusCodeSame(400, 'no hit selected');

        $this->download($job, ['type' => 'everything', 'hits' => [(string) $hit->getId()]]);
        self::assertResponseStatusCodeSame(400, 'unknown sequence type');

        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_download'));
    }

    /**
     * Client nuovo, come un browser su un altro sito: nessun cookie CSRF del nostro dominio.
     */
    public function testCrossOriginDownloadIsRejected(): void
    {
        [$job, $hit] = $this->finishedJob();

        $this->client->request('POST', '/job/' . $job->getId()->toRfc4122() . '/download', ['_token' => 'csrf-token', 'type' => 'entire', 'hits' => [(string) $hit->getId()]], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blast_download'));
    }

    public function testResultsTableIsTheDownloadForm(): void
    {
        [$job, $hit] = $this->finishedJob();

        $crawler = $this->client->request('GET', '/job/' . $job->getId()->toRfc4122());

        $form = $crawler->filter('form[action$="/download"]');
        self::assertSame('false', $form->attr('data-turbo'), 'a file download cannot go through Turbo');
        self::assertSame((string) $hit->getId(), $form->filter('input[name="hits[]"]')->attr('value'));
        self::assertCount(2, $form->filter('input[name="type"]'));
    }

    public function testFiltersNoMatchMessageAndInvalidFilters(): void
    {
        [$job] = $this->finishedJob();
        $url = '/job/' . $job->getId()->toRfc4122();

        $this->client->request('GET', $url . '?cutoff=score&value=1000');
        self::assertSelectorTextContains('.alert-info', 'No comparison meets the cutoff criterion.');

        $this->client->request('GET', $url . '?top=1&cutoff=identity&value=100&sort=score');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'table tbody tr');

        $this->client->request('GET', $url . '?top=7');
        self::assertResponseStatusCodeSame(400);
    }

    public function testManyQueriesArePaginated(): void
    {
        [$job] = $this->finishedJob(numQueries: 45);

        $crawler = $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '?top=5');

        self::assertSame(['1', '2', '3'], $crawler->filter('.pagination a')->each(static fn ($link): string => $link->text()));
        self::assertStringEndsWith('?page=2&top=5', (string) $crawler->filter('.pagination a')->eq(1)->attr('href'));
    }

    public function testHitPageShowsThePairwiseAlignment(): void
    {
        [$job, $hit] = $this->finishedJob();

        $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '/hit/' . $hit->getId());

        self::assertResponseIsSuccessful();
        // Il testo esatto (spazi compresi) è verificato da PairwiseRendererTest: text() comprime gli spazi.
        self::assertSelectorTextContains('pre.bb-alignment', 'Score = 632 bits (350), Expect = 0.0');
        self::assertSelectorTextContains('pre.bb-alignment', 'Strand=Plus/Plus');
        self::assertSelectorTextContains('pre.bb-alignment', 'Query 1 ACGT 4 |||| Sbjct 1 ACGT 4');
        self::assertSelectorTextContains('article h2', 'Escherichia coli section 1');
    }

    public function testHitOfAnotherJobIsNotFound(): void
    {
        [, $hit] = $this->finishedJob();
        $other = $this->savedJob();

        $this->client->request('GET', '/job/' . $other->getId()->toRfc4122() . '/hit/' . $hit->getId());

        self::assertResponseRedirects('/nopainnogain');
    }

    public function testQueryPageListsItsHitsAndUnknownQueriesAreNotFound(): void
    {
        [$job] = $this->finishedJob();
        $url = '/job/' . $job->getId()->toRfc4122() . '/query/';

        $this->client->request('GET', $url . '1');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'article');
        self::assertSelectorNotExists('a:contains("More hits")');

        $this->client->request('GET', $url . '1?page=2');
        self::assertSelectorTextContains('.alert-info', 'No hits on this page.');

        $this->client->request('GET', $url . '9');
        self::assertResponseRedirects('/nopainnogain');
    }

    public function testTabularDownload(): void
    {
        [$job] = $this->finishedJob();

        $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '/hits.tsv?comments=1');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/tab-separated-values; charset=UTF-8');
        self::assertStringContainsString('-commented.tsv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString("q1\tAE000111\t100.000\t4\t0\t0\t1\t4\t1\t4\t0.0\t632\n", $this->client->getInternalResponse()->getContent());
    }

    public function testXmlDownloadWhileTheWorkspaceExists(): void
    {
        [$job] = $this->finishedJob();
        $workspace = self::getContainer()->get(JobWorkspace::class);
        $workspace->write($job->getId(), JobWorkspace::OUTPUT_FILE, '<BlastOutput/>');

        try {
            $crawler = $this->client->request('GET', '/job/' . $job->getId()->toRfc4122());
            self::assertCount(1, $crawler->filter('a[href$="/output.xml"]'));

            $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '/output.xml');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/xml');
            self::assertStringContainsString('attachment; filename=blast-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        } finally {
            $workspace->remove($job->getId());
        }

        $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . '/output.xml');
        self::assertResponseRedirects('/nopainnogain');
    }

    public function testDownloadsOfAnUnfinishedJobAreNotFound(): void
    {
        $job = $this->savedJob();

        foreach (['/hits.tsv', '/output.xml'] as $download) {
            $this->client->request('GET', '/job/' . $job->getId()->toRfc4122() . $download);
            self::assertResponseRedirects('/nopainnogain', null, $download);
        }
    }

    /**
     * @param array<string, string|list<string>> $fields
     */
    private function download(BlastJob $job, array $fields): void
    {
        $this->client->request('POST', '/job/' . $job->getId()->toRfc4122() . '/download', [...$fields, '_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Database pubblico e salvato: lo snapshot dei job deve riferire un database esistente.
     */
    private function publicDatabase(): BlastDatabase
    {
        $database = EntityFactory::database('public_' . bin2hex(random_bytes(4)));
        $database->enableAllCompatiblePrograms();

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        return $database;
    }

    private function savedJob(): BlastJob
    {
        $job = EntityFactory::jobOn($this->publicDatabase());
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * Job blastn terminato: query 1 con un hit (AE000111, 100%), più query senza hit fino a $numQueries.
     *
     * @return array{BlastJob, BlastHit}
     */
    private function finishedJob(int $numQueries = 1): array
    {
        $job = EntityFactory::jobOn($this->publicDatabase());
        $job->start(EntityFactory::now());
        $job->finish(EntityFactory::now(), $numQueries, 1);

        $query = EntityFactory::query($job);
        $hit = EntityFactory::hit($query);
        $hit->addHsp(new BlastHsp($hit, 1, 632.465, 350.0, '0', 4, 4, 0, 4, 1, 4, 1, 4, 1, 1, 'ACGT', '||||', 'ACGT'));

        $this->entityManager->persist($job);
        $this->entityManager->persist($query);
        $this->entityManager->persist($hit);

        for ($position = 2; $position <= $numQueries; ++$position) {
            $this->entityManager->persist(EntityFactory::query($job, $position));
        }

        $this->entityManager->flush();

        return [$job, $hit];
    }
}
