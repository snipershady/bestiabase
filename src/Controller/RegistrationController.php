<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Account\RegistrationData;
use App\Service\Account\RegistrationType;
use App\Service\Account\UserRegistrar;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaPurpose;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Registrazione pubblica: l'account resta disabilitato finché un amministratore non lo abilita.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRegistrar $registrar,
        AltchaChallengeFactory $altcha,
        #[Target('registration')]
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('app_search');
        }

        $data = new RegistrationData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError('Too many registrations from your address. Try again later.'));

                return $this->render('account/register.html.twig', ['form' => $form, 'altcha_challenge' => $altcha->createJson(AltchaPurpose::Registration)], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $registrar->register($data);
            $this->addFlash('success', 'Thank you for registering. We sent you an email to confirm your address. You can sign in now, but you will be able to run searches only after you confirm the address and an administrator enables your account.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('account/register.html.twig', ['form' => $form, 'altcha_challenge' => $altcha->createJson(AltchaPurpose::Registration)], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
