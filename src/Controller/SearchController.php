<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\IncompatibleProgramException;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Service\Blast\Exception\DatabaseUnavailableException;
use App\Service\Blast\Exception\InvalidFastaException;
use App\Service\Blast\Exception\InvalidSearchParametersException;
use App\Service\Blast\Job\BlastJobSubmitter;
use App\Service\SecurityCheck\SecurityCheckGate;
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
        SecurityCheckGate $securityCheck,
        #[Target('blast_search')]
        RateLimiterFactoryInterface $searchLimiter,
    ): Response {
        $current = $this->getUser();

        // Gli anonimi superano prima la verifica di sicurezza (proof of work); chi è collegato no.
        if ($securityCheck->isRequired($request, $current)) {
            return $this->redirectToRoute('app_security_check');
        }

        // Account registrato ma non ancora abilitato: nessuna ricerca, solo l'avviso.
        if ($current instanceof User && !$current->isActive()) {
            return $this->render('search/pending.html.twig', ['user' => $current], new Response(status: $request->isMethod('POST') ? Response::HTTP_FORBIDDEN : Response::HTTP_OK));
        }

        // Le ricerche dal web sono riservate a chi ha il ruolo ROLE_USER o ROLE_ADMIN: gli anonimi
        // vedono la presentazione del progetto e l'invito ad accedere o registrarsi.
        if (!$this->isGranted('ROLE_USER') && !$this->isGranted('ROLE_ADMIN')) {
            return $this->render('search/welcome.html.twig', [], new Response(status: $request->isMethod('POST') ? Response::HTTP_FORBIDDEN : Response::HTTP_OK));
        }

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
                $job = $submitter->submit($data->toSearchRequest(SearchFormType::isAdvanced($form), $current instanceof User ? $current : null));

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
