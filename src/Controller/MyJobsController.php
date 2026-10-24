<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\BlastJobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Storico delle ricerche dell'utente (il legacy non ne aveva: i risultati restavano solo come file).
 */
#[IsGranted('ROLE_USER')]
final class MyJobsController extends AbstractController
{
    public const int PER_PAGE = 25;

    #[Route('/my-jobs', name: 'app_my_jobs', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, BlastJobRepository $jobs, #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1): Response
    {
        $list = $jobs->findForUser($user, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->render('job/mine.html.twig', ['jobs' => $list, 'page' => $page, 'has_next' => \count($list) === self::PER_PAGE]);
    }
}
