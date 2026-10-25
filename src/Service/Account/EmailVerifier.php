<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Mail\AccountMailer;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Conferma che l'utente possieda l'indirizzo e-mail con cui si è registrato: gli si manda un link
 * firmato (con scadenza) e, quando lo segue, l'indirizzo risulta verificato. Il link non basta ad
 * abilitare l'account: quello lo fa un amministratore.
 */
final readonly class EmailVerifier
{
    public const string VALIDITY = 'P1D';

    public function __construct(
        #[Autowire(service: 'uri_signer')]
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        private AccountMailer $mailer,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function sendLink(User $user): void
    {
        $expiresAt = $this->clock->now()->add(new DateInterval(self::VALIDITY));
        $url = $this->uriSigner->sign(
            $this->urlGenerator->generate('app_verify_email', ['id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $expiresAt,
        );

        $this->mailer->sendEmailVerification($user, $url, $expiresAt->format('Y-m-d H:i T'));
    }

    public function confirm(Request $request): EmailVerificationResult
    {
        try {
            $this->uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            return EmailVerificationResult::Expired;
        } catch (SignedUriException) {
            return EmailVerificationResult::Invalid;
        }

        $user = $this->users->find($request->query->getInt('id'));

        if (!$user instanceof User) {
            return EmailVerificationResult::Invalid;
        }

        if ($user->isEmailVerified()) {
            return EmailVerificationResult::AlreadyVerified;
        }

        $user->verifyEmail($this->clock->now());
        $this->entityManager->flush();

        return EmailVerificationResult::Verified;
    }
}
