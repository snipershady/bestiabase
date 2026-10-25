<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Controller\EmailVerificationController;
use App\Controller\SearchController;
use App\Entity\User;
use App\EventSubscriber\NotFoundSubscriber;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Service\Account\EmailVerifier;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastCommandBuilder;
use App\Service\Blast\Fasta\FastaNormalizer;
use App\Service\Blast\Job\BlastJobFactory;
use App\Service\Blast\Job\BlastJobSubmitter;
use App\Service\Blast\Search\GapCostPresets;
use App\Service\Blast\Search\GapCosts;
use App\Service\Blast\Search\GeneticCode;
use App\Service\Blast\Search\SearchParameters;
use App\Service\Blast\Search\SearchParametersNormalizer;
use App\Service\Mail\AccountMailer;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Service\SecurityCheck\SecurityCheckGate;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\UriSigner;

#[CoversClass(EmailVerificationController::class)]
#[CoversClass(EmailVerifier::class)]
#[CoversClass(AccountMailer::class)]
#[UsesClass(BlastBinaryLocator::class)]
#[UsesClass(BlastCommandBuilder::class)]
#[UsesClass(FastaNormalizer::class)]
#[UsesClass(BlastJobFactory::class)]
#[UsesClass(BlastJobSubmitter::class)]
#[UsesClass(GapCostPresets::class)]
#[UsesClass(GapCosts::class)]
#[UsesClass(GeneticCode::class)]
#[UsesClass(SearchParameters::class)]
#[UsesClass(SearchParametersNormalizer::class)]
#[UsesClass(SearchController::class)]
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityCheckGate::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
final class EmailVerificationTest extends FunctionalTestCase
{
    private User $user;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = EntityFactory::pendingUser();
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testTheSignedLinkConfirmsTheAddressButDoesNotEnableTheAccount(): void
    {
        $this->client->request('GET', $this->link('+1 day'));

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Your email address is confirmed.');

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'pending@example.org']) ?? self::fail('user lost');
        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->isActive(), 'an administrator still has to enable the account');

        $this->client->request('GET', $this->link('+1 day'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'was already confirmed');
    }

    public function testExpiredTamperedAndUnknownLinksAreRefused(): void
    {
        $cases = [
            [$this->link('-1 minute'), 'This confirmation link has expired.'],
            [str_replace('id=' . $this->user->getId(), 'id=999999', $this->link('+1 day')), 'This confirmation link is not valid.'],
            ['/verify-email?id=' . $this->user->getId(), 'This confirmation link is not valid.'],
            [$this->link('+1 day', 999_999), 'This confirmation link is not valid.'],
        ];

        foreach ($cases as [$url, $message]) {
            $this->client->request('GET', $url);
            $this->client->followRedirect();

            self::assertSelectorTextContains('.alert-danger', $message, $url);
        }

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'pending@example.org']) ?? self::fail('user lost');
        self::assertFalse($user->isEmailVerified());
    }

    public function testAnUnconfirmedUserCanAskForTheLinkAgain(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.alert-warning', 'pending@example.org');
        $this->client->submit($crawler->selectButton('Send the link again')->form());

        self::assertResponseRedirects('/');
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'We sent you a new confirmation email.');
    }

    public function testResendingIsLimitedAndNeedsAValidToken(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Send the link again')->form();

        for ($i = 1; $i <= 3; ++$i) {
            $this->client->submit($form);
            self::assertResponseRedirects('/');
        }

        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Too many requests.');

        $this->client->request('POST', '/account/verify-email/resend', ['_token' => 'wrong'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testConfirmedUsersAreNotSentAnotherLink(): void
    {
        $this->user->verifyEmail(EntityFactory::now());
        $this->entityManager->flush();
        $this->client->loginUser($this->user);
        $token = $this->client->request('GET', '/')->filter('input[name="_token"]')->count();

        self::assertSame(0, $token, 'the resend form is only shown to unconfirmed users');
    }

    public function testResendingAsAConfirmedUserDoesNothing(): void
    {
        $this->client->loginUser($this->user);
        $token = (string) $this->client->request('GET', '/')->filter('input[name="_token"]')->attr('value');
        $this->user->verifyEmail(EntityFactory::now());
        $this->entityManager->flush();

        $this->client->request('POST', '/account/verify-email/resend', ['_token' => $token], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/');
        self::assertQueuedEmailCount(0);
    }

    private function link(string $expires, ?int $id = null): string
    {
        $signer = self::getContainer()->get('uri_signer');
        self::assertInstanceOf(UriSigner::class, $signer);
        $url = $signer->sign('http://localhost/verify-email?id=' . ($id ?? $this->user->getId()), new DateTimeImmutable($expires));

        return substr($url, \strlen('http://localhost'));
    }
}
