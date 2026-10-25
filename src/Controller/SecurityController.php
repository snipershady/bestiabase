<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaPurpose;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, AltchaChallengeFactory $altcha): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('app_search');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'altcha_challenge' => $altcha->createJson(AltchaPurpose::Login),
        ]);
    }

    /**
     * Intercettata dal firewall: il corpo non viene mai eseguito.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new LogicException('The logout route is handled by the security firewall.');
    }
}
