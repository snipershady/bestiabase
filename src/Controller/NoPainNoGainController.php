<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaPurpose;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dove finiscono le rotte inesistenti (vedi {@see \App\EventSubscriber\NotFoundSubscriber}): una
 * pagina vuota con una proof of work molto costosa, per far sprecare CPU ai bot che scandagliano
 * la piattaforma. Chi ci arriva per errore e la risolve torna alla home.
 */
final class NoPainNoGainController extends AbstractController
{
    #[Route('/nopainnogain', name: 'app_nopainnogain', methods: ['GET'])]
    public function index(AltchaChallengeFactory $challenges): Response
    {
        return $this->render('security_check/trap.html.twig', [
            'altcha_challenge' => $challenges->createJson(AltchaPurpose::Trap),
        ]);
    }
}
