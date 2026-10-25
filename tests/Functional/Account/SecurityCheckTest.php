<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Controller\HelpController;
use App\Controller\PasswordResetController;
use App\Controller\RegistrationController;
use App\Controller\SearchController;
use App\Controller\SecurityCheckController;
use App\Controller\SecurityController;
use App\Entity\User;
use App\EventSubscriber\NotFoundSubscriber;
use App\Form\SearchFormClientConfig;
use App\Form\SearchFormData;
use App\Form\SearchFormType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\SecurityHeadersListener;
use App\Service\Account\EmailVerifier;
use App\Service\Account\ForgotPasswordType;
use App\Service\Account\NewPasswordType;
use App\Service\Account\NewUserNotifier;
use App\Service\Account\RegistrationType;
use App\Service\Account\UserRegistrar;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaSecret;
use App\Service\Altcha\AltchaVerifier;
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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SecurityCheckController::class)]
#[CoversClass(SecurityCheckGate::class)]
#[CoversClass(SecurityCheckCookie::class)]
#[CoversClass(AltchaVerifier::class)]
#[CoversClass(AltchaChallengeFactory::class)]
#[UsesClass(EmailVerifier::class)]
#[UsesClass(ForgotPasswordType::class)]
#[UsesClass(NewPasswordType::class)]
#[UsesClass(NewUserNotifier::class)]
#[UsesClass(RegistrationType::class)]
#[UsesClass(UserRegistrar::class)]
#[UsesClass(AltchaDifficulties::class)]
#[UsesClass(AltchaDifficulty::class)]
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
#[UsesClass(HelpController::class)]
#[UsesClass(PasswordResetController::class)]
#[UsesClass(RegistrationController::class)]
#[UsesClass(SearchController::class)]
#[UsesClass(SecurityController::class)]
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(SearchFormClientConfig::class)]
#[UsesClass(SearchFormData::class)]
#[UsesClass(SearchFormType::class)]
#[UsesClass(AccountMailer::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(ResetPasswordRequestRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
#[UsesClass(AltchaSecret::class)]
final class SecurityCheckTest extends FunctionalTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Questi test partono da un visitatore che non ha ancora superato la verifica.
        $this->client->getCookieJar()->clear();
    }

    public function testAnonymousVisitorsAreSentToTheSecurityCheckBeforeTheIndex(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/security-check');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Security check');
        self::assertCount(1, $crawler->filter('altcha-widget[auto="onload"]'));
        self::assertCount(1, $crawler->filter('input[name="altcha"]'));
        self::assertSelectorExists('script[type="module"][src="/js/altcha.min.js"][nonce]');
    }

    public function testSearchesPostedWithoutTheCheckAreNotAccepted(): void
    {
        $this->client->request('POST', '/', ['search_form' => ['program' => 'blastn', 'query' => 'ACGT']]);

        self::assertResponseRedirects('/security-check');
    }

    public function testASolvedChallengeGrantsAValidCookie(): void
    {
        $this->client->request('POST', '/security-check', ['altcha' => $this->altchaPayload(AltchaPurpose::SecurityCheck)]);

        self::assertResponseRedirects('/');
        self::assertNotNull($this->client->getCookieJar()->get(SecurityCheckCookie::NAME));

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#about-title', 'About BestiaBase');

        $this->client->request('GET', '/security-check');
        self::assertResponseRedirects('/', null, 'already checked');
    }

    public function testAnInvalidSolutionRestartsTheCheck(): void
    {
        $this->client->request('POST', '/security-check', ['altcha' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.alert-danger', 'The security check failed.');
        self::assertNull($this->client->getCookieJar()->get(SecurityCheckCookie::NAME));
    }

    public function testASolutionForAnotherPurposeIsNotEnough(): void
    {
        $this->client->request('POST', '/security-check', ['altcha' => $this->altchaPayload(AltchaPurpose::Login)]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSignedInUsersNeverSeeTheCheck(): void
    {
        $user = EntityFactory::user();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/security-check');
        self::assertResponseRedirects('/');
    }

    public function testThePagesThatAreNotTheIndexDoNotNeedIt(): void
    {
        foreach (['/help', '/login', '/register', '/reset-password'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful($path);
        }
    }
}
