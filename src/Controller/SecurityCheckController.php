<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaVerifierInterface;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Service\SecurityCheck\SecurityCheckGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Verifica di sicurezza" prima della home per i visitatori anonimi (come la modalità Under
 * Attack di Cloudflare): il browser risolve da solo una proof of work e riceve un cookie firmato.
 * Chi è collegato non la vede mai.
 */
final class SecurityCheckController extends AbstractController
{
    #[Route('/security-check', name: 'app_security_check', methods: ['GET', 'POST'])]
    public function check(
        Request $request,
        SecurityCheckGate $gate,
        SecurityCheckCookie $cookie,
        AltchaChallengeFactory $challenges,
        AltchaVerifierInterface $verifier,
    ): Response {
        if (!$gate->isRequired($request, $this->getUser())) {
            return $this->redirectToRoute('app_search');
        }

        if ($request->isMethod('POST') && $verifier->verify($request->request->getString('altcha'), AltchaPurpose::SecurityCheck)) {
            $response = $this->redirectToRoute('app_search');
            $response->headers->setCookie($cookie->issue($request));

            return $response;
        }

        return $this->render('security_check/index.html.twig', [
            'altcha_challenge' => $challenges->createJson(AltchaPurpose::SecurityCheck),
            'failed' => $request->isMethod('POST'),
        ], new Response(status: $request->isMethod('POST') ? Response::HTTP_FORBIDDEN : Response::HTTP_OK));
    }
}
