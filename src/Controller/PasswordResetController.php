<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Account\ForgotPasswordData;
use App\Service\Account\ForgotPasswordType;
use App\Service\Account\NewPasswordData;
use App\Service\Account\NewPasswordType;
use App\Service\Mail\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Password dimenticata: il link a tempo arriva per e-mail e il token vale una sola volta. La
 * risposta non rivela mai se l'indirizzo corrisponde a un account.
 */
#[Route('/reset-password')]
final class PasswordResetController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        AccountMailer $mailer,
        #[Target('password_reset')]
        RateLimiterFactoryInterface $passwordResetLimiter,
    ): Response {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('app_search');
        }

        $data = new ForgotPasswordData();
        $form = $this->createForm(ForgotPasswordType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('account/forgot_password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        if (!$passwordResetLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $form->addError(new FormError('Too many requests from your address. Try again later.'));

            return $this->render('account/forgot_password.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
        }

        $user = $users->findOneBy(['email' => (string) $data->email]);

        if ($user instanceof User && $user->isActive()) {
            $this->sendResetLink($user, $mailer);
        }

        // Stessa risposta con indirizzo sconosciuto, account disabilitato o richiesta ravvicinata.
        return $this->redirectToRoute('app_check_email');
    }

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('account/check_email.html.twig', ['lifetimeMinutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60)]);
    }

    /**
     * Il token arriva nell'URL dell'e-mail: lo si sposta in sessione e si rimanda a un URL senza,
     * così non finisce nel Referer né nella history.
     */
    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, ?string $token = null): Response
    {
        if ($token !== null) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if ($token === null) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->cleanSessionAfterReset();
            $this->addFlash('danger', 'This reset link is invalid or has expired. Request a new one.');

            return $this->redirectToRoute('app_forgot_password');
        }

        // Le richieste di reset sono create solo per i User (vedi ResetPasswordRequestRepository).
        if (!$user instanceof User) {
            throw new LogicException(\sprintf('Expected a "%s" from the reset token, got "%s".', User::class, $user::class));
        }

        $data = new NewPasswordData();
        $form = $this->createForm(NewPasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Il token vale una sola volta.
            $this->resetPasswordHelper->removeResetRequest($token);
            $user->changePassword($passwordHasher->hashPassword($user, (string) $data->password));
            $this->entityManager->flush();
            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Your password has been changed. You can sign in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('account/reset_password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function sendResetLink(User $user, AccountMailer $mailer): void
    {
        try {
            $token = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Richiesta ravvicinata (throttling): il link precedente è ancora valido.
            return;
        }

        $mailer->sendPasswordReset($user, $token);
    }
}
