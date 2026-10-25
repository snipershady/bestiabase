<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Controller\RegistrationController;
use App\Controller\SecurityController;
use App\Entity\User;
use App\Enum\UserRole;
use App\EventSubscriber\NotFoundSubscriber;
use App\Repository\UserRepository;
use App\Security\SecurityHeadersListener;
use App\Service\Account\DeliverableEmail;
use App\Service\Account\DeliverableEmailValidator;
use App\Service\Account\EmailVerifier;
use App\Service\Account\NewPasswordType;
use App\Service\Account\NewUserNotifier;
use App\Service\Account\RegistrationData;
use App\Service\Account\RegistrationType;
use App\Service\Account\UserRegistrar;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaSecret;
use App\Service\Altcha\AltchaSolved;
use App\Service\Altcha\AltchaSolvedValidator;
use App\Service\Altcha\AltchaVerifier;
use App\Service\Mail\AccountMailer;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FakeMxResolver;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(RegistrationController::class)]
#[CoversClass(UserRegistrar::class)]
#[CoversClass(EmailVerifier::class)]
#[CoversClass(NewUserNotifier::class)]
#[CoversClass(AccountMailer::class)]
#[CoversClass(RegistrationData::class)]
#[CoversClass(RegistrationType::class)]
#[CoversClass(NewPasswordType::class)]
#[UsesClass(DeliverableEmail::class)]
#[UsesClass(DeliverableEmailValidator::class)]
#[UsesClass(AltchaChallengeFactory::class)]
#[UsesClass(AltchaDifficulties::class)]
#[UsesClass(AltchaDifficulty::class)]
#[UsesClass(AltchaSolved::class)]
#[UsesClass(AltchaSolvedValidator::class)]
#[UsesClass(AltchaVerifier::class)]
#[UsesClass(SecurityController::class)]
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(AltchaSecret::class)]
final class RegistrationTest extends FunctionalTestCase
{
    public function testRegistrationCreatesADisabledAccount(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Register')->form();
        $form['registration[email]'] = 'new@example.org';
        $form['registration[username]'] = 'newcomer';
        $form['registration[password][first]'] = 'a long enough password';
        $form['registration[password][second]'] = 'a long enough password';
        $form['registration[altcha]'] = $this->altchaPayload(AltchaPurpose::Registration);
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'an administrator enables your account');

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'new@example.org']) ?? self::fail('user not created');
        self::assertFalse($user->isActive());
        self::assertSame([UserRole::Disabled->value], $user->getRoles());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'a long enough password'));
    }

    public function testInvalidRegistrationsAreRejected(): void
    {
        $existing = EntityFactory::user('taken@example.org', 'taken');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $cases = [
            'This email address is already registered.' => ['taken@example.org', 'someone', 'a long enough password', 'a long enough password'],
            'This username is already taken.' => ['free@example.org', 'taken', 'a long enough password', 'a long enough password'],
            'This value is too short.' => ['free@example.org', 'someone', 'short', 'short'],
            'The passwords do not match.' => ['free@example.org', 'someone', 'a long enough password', 'another long password'],
            'This value is not a valid email address.' => ['not-an-email', 'someone', 'a long enough password', 'a long enough password'],
        ];

        foreach ($cases as $message => [$email, $username, $first, $second]) {
            $form = $this->client->request('GET', '/register')->selectButton('Register')->form();
            $form['registration[email]'] = $email;
            $form['registration[username]'] = $username;
            $form['registration[password][first]'] = $first;
            $form['registration[password][second]'] = $second;
            $form['registration[altcha]'] = $this->altchaPayload(AltchaPurpose::Registration);
            $this->client->submit($form);

            self::assertResponseStatusCodeSame(422, $message);
            self::assertSelectorTextContains('form', $message);
        }

        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'free@example.org']));
    }

    public function testSignedInUsersAreSentBackToTheHomePage(): void
    {
        $user = EntityFactory::user();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/register');

        self::assertResponseRedirects('/');
    }

    public function testRegistrationsAreRateLimitedPerAddress(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $form = $this->client->request('GET', '/register')->selectButton('Register')->form();
            $form['registration[email]'] = \sprintf('bot%d@example.org', $i);
            $form['registration[username]'] = 'bot' . $i;
            $form['registration[password][first]'] = 'a long enough password';
            $form['registration[password][second]'] = 'a long enough password';
            $form['registration[altcha]'] = $this->altchaPayload(AltchaPurpose::Registration);
            $this->client->submit($form);

            if ($i <= 5) {
                self::assertResponseRedirects('/login');
            }
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('form', 'Too many registrations');
        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'bot6@example.org']));
    }

    public function testARegistrationWithoutAValidSecurityCheckIsRejected(): void
    {
        $form = $this->client->request('GET', '/register')->selectButton('Register')->form();
        $form['registration[email]'] = 'bot@example.org';
        $form['registration[username]'] = 'bot';
        $form['registration[password][first]'] = 'a long enough password';
        $form['registration[password][second]'] = 'a long enough password';
        $form['registration[altcha]'] = 'forged';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The security check failed.');
        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'bot@example.org']));
    }

    public function testTheEmailDomainMustBeAbleToReceiveMail(): void
    {
        $form = $this->client->request('GET', '/register')->selectButton('Register')->form();
        $form['registration[email]'] = 'someone@' . FakeMxResolver::NO_MX_DOMAIN;
        $form['registration[username]'] = 'someone';
        $form['registration[password][first]'] = 'a long enough password';
        $form['registration[password][second]'] = 'a long enough password';
        $form['registration[altcha]'] = $this->altchaPayload(AltchaPurpose::Registration);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This email address cannot be used: No MX record for domain.');
    }

    public function testTheUserIsAskedToConfirmTheAddressAndTheAdministratorsAreNotified(): void
    {
        $admin = EntityFactory::user('admin@example.org', 'admin');
        $admin->grantRole(UserRole::Admin);

        $suspended = EntityFactory::user('suspended@example.org', 'suspended');
        $suspended->grantRole(UserRole::Admin);
        $suspended->deactivate();

        $this->entityManager->persist($admin);
        $this->entityManager->persist($suspended);
        $this->entityManager->flush();

        $form = $this->client->request('GET', '/register')->selectButton('Register')->form();
        $form['registration[email]'] = 'new@example.org';
        $form['registration[username]'] = 'newcomer';
        $form['registration[password][first]'] = 'a long enough password';
        $form['registration[password][second]'] = 'a long enough password';
        $form['registration[altcha]'] = $this->altchaPayload(AltchaPurpose::Registration);
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        self::assertQueuedEmailCount(2);

        $verification = self::getMailerMessage(0);
        $notification = self::getMailerMessage(1);
        self::assertInstanceOf(Email::class, $verification);
        self::assertInstanceOf(Email::class, $notification);
        self::assertSame('new@example.org', $verification->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#https?://[^/\s]+/verify-email\?[^\s]+#', (string) $verification->getTextBody());
        self::assertSame('admin@example.org', $notification->getTo()[0]->getAddress());
        self::assertSame('New registration: newcomer', $notification->getSubject());
        self::assertStringContainsString('new@example.org', (string) $notification->getTextBody());
        self::assertStringContainsString('/admin/users', (string) $notification->getTextBody());
    }

    public function testRegistrationWorksWithoutAnyAdministrator(): void
    {
        $form = $this->client->request('GET', '/register')->selectButton('Register')->form();
        $form['registration[email]'] = 'new@example.org';
        $form['registration[username]'] = 'newcomer';
        $form['registration[password][first]'] = 'a long enough password';
        $form['registration[password][second]'] = 'a long enough password';
        $form['registration[altcha]'] = $this->altchaPayload(AltchaPurpose::Registration);
        $this->client->submit($form);

        self::assertQueuedEmailCount(1);
    }
}
