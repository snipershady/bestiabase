<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\BlastJobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class JobAdminController extends AbstractController
{
    public const int PER_PAGE = 50;

    #[Route('/admin/jobs', name: 'app_admin_jobs', methods: ['GET'])]
    public function index(BlastJobRepository $jobs, #[MapQueryParameter(options: ['min_range' => 1])] int $page = 1): Response
    {
        $list = $jobs->findRecent(($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->render('admin/job/index.html.twig', ['jobs' => $list, 'page' => $page, 'has_next' => \count($list) === self::PER_PAGE]);
    }
}
