<?php

declare(strict_types=1);

namespace App\Controller;

use App\Blast\Exception\DatabaseUnavailableException;
use App\Blast\Exception\InvalidFastaException;
use App\Blast\Exception\InvalidSearchParametersException;
use App\Blast\Job\BlastJobSubmitter;
use App\Entity\User;
use App\Exception\IncompatibleProgramException;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Form di ricerca (ex viroblast.php + blastresult.php): valida, accoda il job e rimanda alla
 * pagina del job. BLAST non viene mai eseguito dentro la richiesta HTTP.
 */
final class SearchController extends AbstractController
{
    #[Route('/', name: 'app_search', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        BlastJobSubmitter $submitter,
        #[Target('blast_search')]
        RateLimiterFactoryInterface $searchLimiter,
    ): Response {
        // Il form popola direttamente $data (data_class): non serve rileggerlo con getData().
        $data = new SearchFormData();
        $form = $this->createForm(SearchFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $searchLimiter->create($request->getClientIp() ?? 'unknown')->consume();

            if (!$limit->isAccepted()) {
                $form->addError(new FormError(\sprintf(
                    'Too many searches from your address. Try again after %s.',
                    $limit->getRetryAfter()->format('H:i'),
                )));

                return $this->render('search/index.html.twig', $this->view($form->createView()), new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            try {
                $user = $this->getUser();
                $job = $submitter->submit($data->toSearchRequest(SearchFormType::isAdvanced($form), $user instanceof User ? $user : null));

                return $this->redirectToRoute('app_job_show', ['id' => $job->getId()->toRfc4122()]);
            } catch (InvalidFastaException $exception) {
                $form->get('query')->addError(new FormError($exception->getMessage()));
            } catch (IncompatibleProgramException|DatabaseUnavailableException $exception) {
                $form->get('databases')->addError(new FormError($exception->getMessage()));
            } catch (InvalidSearchParametersException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        // 422 su invio non valido: Turbo mostra gli errori invece di ignorare la risposta.
        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('search/index.html.twig', $this->view($form->createView()), new Response(status: $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function view(FormView $form): array
    {
        return [
            'form' => $form,
            'programs' => SearchFormClientConfig::programs(),
            'gap_costs' => SearchFormClientConfig::gapCosts(),
        ];
    }
}
