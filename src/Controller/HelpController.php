<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pagine di aiuto (sostituiscono docs/*.html del legacy, con testi riscritti).
 */
#[Route('/help')]
final class HelpController extends AbstractController
{
    #[Route('', name: 'app_help', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help/index.html.twig');
    }

    #[Route('/parameters', name: 'app_help_parameters', methods: ['GET'])]
    public function parameters(): Response
    {
        return $this->render('help/parameters.html.twig');
    }
}
