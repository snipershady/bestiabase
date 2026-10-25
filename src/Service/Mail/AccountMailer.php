<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/**
 * E-mail transazionali dell'account. L'invio passa da Messenger (SendEmailMessage è instradato
 * su "async"): la richiesta HTTP non attende l'SMTP e un errore del provider non la fa fallire.
 */
final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'MAILER_FROM_ADDRESS')]
        private string $fromAddress,
    ) {
    }

    public function sendPasswordReset(User $user, ResetPasswordToken $token): void
    {
        $this->mailer->send($this->email($user, 'Reset your BestiaBase password', 'password_reset')->context([
            'username' => $user->getUsername(),
            'resetUrl' => $this->urlGenerator->generate('app_reset_password', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'expiresAt' => $token->getExpiresAt()->format('Y-m-d H:i T'),
        ]));
    }

    public function sendAccountEnabled(User $user): void
    {
        $this->mailer->send($this->email($user, 'Your BestiaBase account is enabled', 'account_enabled')->context([
            'username' => $user->getUsername(),
            'loginUrl' => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]));
    }

    /**
     * @param string $verificationUrl indirizzo firmato e a scadenza che conferma l'e-mail
     */
    public function sendEmailVerification(User $user, string $verificationUrl, string $expiresAt): void
    {
        $this->mailer->send($this->email($user, 'Confirm your BestiaBase email address', 'email_verification')->context([
            'username' => $user->getUsername(),
            'verificationUrl' => $verificationUrl,
            'expiresAt' => $expiresAt,
        ]));
    }

    /**
     * Avvisa un amministratore che qualcuno si è registrato e attende di essere abilitato.
     */
    public function sendNewUserNotification(User $administrator, User $newUser): void
    {
        $this->mailer->send($this->email($administrator, \sprintf('New registration: %s', $newUser->getUsername()), 'new_user')->context([
            'administrator' => $administrator->getUsername(),
            'newUsername' => $newUser->getUsername(),
            'newEmail' => $newUser->getEmail(),
            'registeredAt' => $newUser->getCreatedAt()->format('Y-m-d H:i T'),
            'usersUrl' => $this->urlGenerator->generate('app_admin_users', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]));
    }

    private function email(User $user, string $subject, string $template): TemplatedEmail
    {
        return new TemplatedEmail()
            ->from(new Address($this->fromAddress, 'BestiaBase'))
            ->to(new Address($user->getEmail(), $user->getUsername()))
            ->subject($subject)
            ->htmlTemplate(\sprintf('email/%s.html.twig', $template))
            ->textTemplate(\sprintf('email/%s.txt.twig', $template));
    }
}
