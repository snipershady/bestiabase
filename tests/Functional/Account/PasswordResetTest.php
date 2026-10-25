<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Controller\PasswordResetController;
use App\Controller\SecurityController;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\EventSubscriber\NotFoundSubscriber;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Security\SecurityHeadersListener;
use App\Service\Account\ForgotPasswordData;
use App\Service\Account\ForgotPasswordType;
use App\Service\Account\NewPasswordData;
use App\Service\Account\NewPasswordType;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaSecret;
use App\Service\Mail\AccountMailer;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[CoversClass(PasswordResetController::class)]
#[CoversClass(ForgotPasswordData::class)]
#[CoversClass(ForgotPasswordType::class)]
#[CoversClass(NewPasswordData::class)]
#[CoversClass(NewPasswordType::class)]
#[CoversClass(AccountMailer::class)]
#[CoversClass(ResetPasswordRequest::class)]
#[CoversClass(ResetPasswordRequestRepository::class)]
#[UsesClass(AltchaChallengeFactory::class)]
#[UsesClass(AltchaDifficulties::class)]
#[UsesClass(AltchaDifficulty::class)]
#[UsesClass(SecurityController::class)]
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(AltchaSecret::class)]
final class PasswordResetTest extends FunctionalTestCase
{
    private User $user;

    /** Il logger delle e-mail si azzera a ogni richiesta: si legge subito dopo l'invio del form. */
    private ?Email $sent = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = EntityFactory::user('lab@example.org', 'lab');
        $this->user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'the old password'));

        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testAKnownAddressReceivesAOneTimeLink(): void
    {
        $this->requestLink('lab@example.org');

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'valid for 60 minutes');

        $path = $this->resetPathFromEmail();
        $this->client->request('GET', $path);
        self::assertResponseRedirects('/reset-password/reset', null, 'the token leaves the URL');
        $crawler = $this->client->followRedirect();

        $form = $crawler->selectButton('Change password')->form();
        $form['new_password[password][first]'] = 'the brand new password';
        $form['new_password[password][second]'] = 'the brand new password';
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Your password has been changed');

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'lab@example.org']) ?? self::fail('user lost');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'the brand new password'));

        $this->client->request('GET', $path);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password', null, 'the link works only once');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'invalid or has expired');
    }

    public function testTheResponseDoesNotRevealWhetherTheAccountExists(): void
    {
        $this->requestLink('nobody@example.org');
        self::assertResponseRedirects('/reset-password/check-email');

        $disabled = EntityFactory::user('pending@example.org', 'pending');
        $disabled->deactivate();

        $this->entityManager->persist($disabled);
        $this->entityManager->flush();

        $this->requestLink('pending@example.org');
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(0);
    }

    public function testARepeatedRequestIsThrottledSilently(): void
    {
        $this->requestLink('lab@example.org');
        self::assertQueuedEmailCount(1);

        $this->requestLink('lab@example.org');

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(0);
    }

    public function testInvalidInputAndPagesOutOfSequence(): void
    {
        $this->requestLink('not-an-email');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/reset-password/check-email');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/reset-password/reset');
        self::assertResponseRedirects('/nopainnogain');

        $this->client->request('GET', '/reset-password/reset/not-a-valid-token');
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    public function testATokenThatDoesNotBelongToAUserIsAProgrammingError(): void
    {
        self::getContainer()->set(ResetPasswordHelperInterface::class, new class implements ResetPasswordHelperInterface {
            #[Override]
            public function generateResetToken(object $user, ?int $resetRequestLifetime = null): ResetPasswordToken
            {
                throw new LogicException('not used');
            }

            #[Override]
            public function validateTokenAndFetchUser(string $fullToken): stdClass
            {
                return new stdClass();
            }

            #[Override]
            public function removeResetRequest(string $fullToken): void
            {
            }

            #[Override]
            public function getTokenLifetime(): int
            {
                return 3600;
            }

            public function generateFakeResetToken(?int $resetRequestLifetime = null): ResetPasswordToken
            {
                throw new LogicException('not used');
            }
        });
        $this->client->catchExceptions(false);
        $this->client->request('GET', '/reset-password/reset/any-token');

        $this->expectException(LogicException::class);
        $this->client->followRedirect();
    }

    public function testTheNewPasswordIsValidated(): void
    {
        $this->requestLink('lab@example.org');
        $this->client->request('GET', $this->resetPathFromEmail());
        $form = $this->client->followRedirect()->selectButton('Change password')->form();
        $form['new_password[password][first]'] = 'short';
        $form['new_password[password][second]'] = 'short';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This value is too short.');
    }

    public function testRequestsAreRateLimitedPerAddress(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->requestLink('nobody@example.org');
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('form', 'Too many requests');
    }

    public function testSignedInUsersAreSentBackToTheHomePage(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request(Request::METHOD_GET, '/reset-password');

        self::assertResponseRedirects('/');
    }

    private function requestLink(string $email): void
    {
        $form = $this->client->request('GET', '/reset-password')->selectButton('Send the reset link')->form();
        $form['forgot_password[email]'] = $email;
        $this->client->submit($form);

        $message = self::getMailerMessage();
        $this->sent = $message instanceof Email ? $message : null;
    }

    private function resetPathFromEmail(): string
    {
        $email = $this->sent;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('lab@example.org', $email->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('#https?://[^/\s]+(/reset-password/reset/[A-Za-z0-9]+)#', (string) $email->getTextBody(), $matches));

        return $matches[1];
    }
}
