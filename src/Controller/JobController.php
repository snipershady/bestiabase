<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BlastDownload;
use App\Entity\BlastHit;
use App\Entity\BlastHsp;
use App\Entity\BlastJob;
use App\Entity\BlastQuery;
use App\Enum\JobStatus;
use App\Repository\BlastHitRepository;
use App\Security\BlastJobVoter;
use App\Service\Blast\Job\JobStatusView;
use App\Service\Blast\JobWorkspace;
use App\Service\Blast\Result\DownloadRequest;
use App\Service\Blast\Result\HitFilter;
use App\Service\Blast\Result\HspAlignment;
use App\Service\Blast\Result\PairwiseRenderer;
use App\Service\Blast\Result\SequenceExtractor;
use App\Service\Blast\Result\TabularExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stato e risultati di un job (ex blastresult.php, blastN.html e download dei file in data/).
 *
 * Ogni action richiede BLAST_JOB_VIEW ({@see BlastJobVoter}): proprietario o amministratore, job
 * anonimi per UUID (v7, non enumerabile come il jobid legacy time().rand()), e in ogni caso
 * l'accesso ancora valido ai database del job.
 */
#[Route('/job/{id}', requirements: ['id' => Requirement::UUID])]
final class JobController extends AbstractController
{
    /** Hit per pagina nella vista pairwise di una query. */
    public const int HITS_PER_PAGE = 10;

    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('', name: 'app_job_show', methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'id')]
        BlastJob $job,
        BlastHitRepository $hits,
        JobWorkspace $workspace,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        HitFilter $filter = new HitFilter(),
    ): Response {
        $finished = $job->getStatus() === JobStatus::Finished;
        [$firstQuery, $lastQuery] = $filter->queryRange();

        return $this->render('job/show.html.twig', [
            'job' => JobStatusView::fromJob($job),
            'mol_type' => $job->getProgram()->databaseMolType(),
            'filter' => $filter,
            'rows' => $finished ? $hits->findRows($job, $filter, $firstQuery, $lastQuery) : [],
            'pages' => max(1, (int) ceil(($job->getNumQueries() ?? 0) / HitFilter::QUERIES_PER_PAGE)),
            'has_xml' => $finished && is_file($workspace->path($job->getId(), JobWorkspace::OUTPUT_FILE)),
        ]);
    }

    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('/status', name: 'app_job_status', methods: ['GET'])]
    public function status(#[MapEntity(id: 'id')] BlastJob $job): JsonResponse
    {
        $response = $this->json(JobStatusView::fromJob($job));
        // Lo stato cambia mentre il client fa polling: nessuna cache intermedia.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('/hit/{hitId}', name: 'app_job_hit', requirements: ['hitId' => Requirement::DIGITS], methods: ['GET'])]
    public function hit(#[MapEntity(id: 'id')] BlastJob $job, int $hitId, BlastHitRepository $hits, PairwiseRenderer $renderer): Response
    {
        $hit = $hits->findOneInJob($job, $hitId) ?? throw $this->createNotFoundException();

        return $this->render('job/hit.html.twig', [
            'job' => JobStatusView::fromJob($job),
            'mol_type' => $job->getProgram()->databaseMolType(),
            'hit' => $hit,
            'alignments' => $this->alignments($hit, $job, $renderer),
        ]);
    }

    /**
     * Tutti gli allineamenti di una query, paginati per hit (sostituisce le pagine blastN.html,
     * che il legacy divideva per dimensione del file).
     */
    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('/query/{position}', name: 'app_job_query', requirements: ['position' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function query(
        #[MapEntity(id: 'id')]
        BlastJob $job,
        int $position,
        BlastHitRepository $hits,
        PairwiseRenderer $renderer,
        EntityManagerInterface $entityManager,
        #[MapQueryParameter(options: ['min_range' => 1])]
        int $page = 1,
    ): Response {
        $query = $entityManager->getRepository(BlastQuery::class)->findOneBy(['job' => $job, 'position' => $position])
            ?? throw $this->createNotFoundException();
        $queryHits = $hits->findForQuery($job, $position, ($page - 1) * self::HITS_PER_PAGE, self::HITS_PER_PAGE);

        return $this->render('job/query.html.twig', [
            'job' => JobStatusView::fromJob($job),
            'mol_type' => $job->getProgram()->databaseMolType(),
            'query' => $query,
            'hits' => array_map(fn (BlastHit $hit): array => ['hit' => $hit, 'alignments' => $this->alignments($hit, $job, $renderer)], $queryHits),
            'page' => $page,
            'has_next' => \count($queryHits) === self::HITS_PER_PAGE,
            'num_queries' => $job->getNumQueries() ?? 0,
        ]);
    }

    /**
     * Output XML originale di BLAST (`-outfmt 5`), finché il workspace del job esiste.
     */
    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('/output.xml', name: 'app_job_output_xml', methods: ['GET'])]
    public function outputXml(#[MapEntity(id: 'id')] BlastJob $job, JobWorkspace $workspace): BinaryFileResponse
    {
        $path = $workspace->path($job->getId(), JobWorkspace::OUTPUT_FILE);

        if ($job->getStatus() !== JobStatus::Finished || !is_file($path)) {
            throw $this->createNotFoundException('The BLAST output of this job is no longer available.');
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'application/xml']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'blast-' . $job->getId()->toRfc4122() . '.xml');

        return $response;
    }

    /**
     * Risultati in formato tabulare (`-outfmt 6`, o 7 con `?comments=1`), generati dal database.
     */
    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('/hits.tsv', name: 'app_job_tabular', methods: ['GET'])]
    public function tabular(
        #[MapEntity(id: 'id')]
        BlastJob $job,
        TabularExporter $exporter,
        #[MapQueryParameter]
        bool $comments = false,
    ): StreamedResponse {
        if ($job->getStatus() !== JobStatus::Finished) {
            throw $this->createNotFoundException('The job has no results yet.');
        }

        $response = new StreamedResponse(static function () use ($exporter, $job, $comments): void {
            $exporter->export($job, $comments, static function (string $text): void {
                echo $text;
            });
        });
        $response->headers->set('Content-Type', 'text/tab-separated-values; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('blast-%s%s.tsv', $job->getId()->toRfc4122(), $comments ? '-commented' : ''),
        ));

        return $response;
    }

    /**
     * Sequenze soggetto degli hit selezionati in FASTA (ex sequence.php + download.php): il file
     * viene generato al momento, senza file permanenti, e la richiesta resta registrata.
     */
    #[IsGranted(BlastJobVoter::VIEW, 'job')]
    #[Route('/download', name: 'app_job_download', methods: ['POST'])]
    public function download(
        #[MapEntity(id: 'id')]
        BlastJob $job,
        Request $request,
        BlastHitRepository $hits,
        SequenceExtractor $extractor,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        DownloadRequest $download,
    ): StreamedResponse {
        // 422 come il form di ricerca: un AccessDenied verrebbe trasformato in 401 per gli anonimi.
        if (!$this->isCsrfTokenValid('download', $request->getPayload()->getString('_token'))) {
            throw new UnprocessableEntityHttpException('Invalid CSRF token. Reload the page and try again.');
        }

        $selected = $hits->findSelectedInJob($job, array_values(array_unique($download->hits)));

        // Hit di un altro job o inesistenti: nessun download parziale.
        if (\count($selected) !== \count(array_unique($download->hits))) {
            throw $this->createNotFoundException('Some of the selected hits do not belong to this job.');
        }

        $entityManager->persist(new BlastDownload($job, $download->type, $selected, $clock->now()));
        $entityManager->flush();

        $response = new StreamedResponse(static function () use ($extractor, $job, $selected, $download): void {
            foreach ($extractor->extract($job, $selected, $download->type) as $record) {
                echo $record;
            }
        });
        $response->headers->set('Content-Type', 'text/x-fasta; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('blast-%s-%s.fasta', $job->getId()->toRfc4122(), $download->type->value),
        ));

        return $response;
    }

    /**
     * @return list<HspAlignment>
     */
    private function alignments(BlastHit $hit, BlastJob $job, PairwiseRenderer $renderer): array
    {
        return array_map(static fn (BlastHsp $hsp): HspAlignment => $renderer->render($hsp, $job->getProgram()), $hit->getHsps());
    }
}
