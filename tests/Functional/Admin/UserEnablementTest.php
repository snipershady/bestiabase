<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\UserAdminController;
use App\Entity\User;
use App\Enum\UserRole;
use App\EventSubscriber\NotFoundSubscriber;
use App\Form\Admin\UserAdminData;
use App\Form\Admin\UserAdminType;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use App\Security\SecurityHeadersListener;
use App\Service\Mail\AccountMailer;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionProperty;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;

#[CoversClass(UserAdminController::class)]
#[CoversClass(AccountMailer::class)]
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(UserAdminData::class)]
#[UsesClass(UserAdminType::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
final class UserEnablementTest extends FunctionalTestCase
{
    private User $pending;

    private User $unconfirmed;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $admin = EntityFactory::user('admin@example.org', 'admin');
        $admin->grantRole(UserRole::Admin);

        $this->pending = EntityFactory::user('pending@example.org', 'pending');
        $this->pending->deactivate();

        $this->entityManager->persist($admin);
        $this->entityManager->persist($this->pending);

        $this->unconfirmed = EntityFactory::pendingUser('unconfirmed@example.org', 'unconfirmed');
        $this->entityManager->persist($this->unconfirmed);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
    }

    public function testTheListOffersToEnableOnlyDisabledAccounts(): void
    {
        $crawler = $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action$="/enable"]'));
        self::assertSelectorTextContains('tr:contains("pending@example.org")', 'disabled');
        self::assertSelectorTextContains('tr:contains("pending@example.org")', 'confirmed');
        self::assertSelectorTextContains('tr:contains("unconfirmed@example.org")', 'not confirmed');
    }

    public function testEnablingAnAccountNotifiesTheUser(): void
    {
        $this->enable($this->pending, $this->tokenFromTheList());

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('pending@example.org', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('enabled your BestiaBase account', (string) $email->getTextBody());

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'pending@example.org']) ?? self::fail('user lost');
        self::assertTrue($user->isActive());
    }

    public function testEnablingAnAccountAlreadyEnabledSendsNoSecondEmail(): void
    {
        $token = $this->tokenFromTheList();
        $this->enable($this->pending, $token);
        $this->enable($this->pending, $token);

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(0);
    }

    public function testEnablingFromTheFormNotifiesTheUserToo(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/' . $this->pending->getId());
        $form = $crawler->selectButton('Save')->form();
        $active = $form['user_admin[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(1);
    }

    public function testSavingAnEnabledAccountSendsNothing(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/' . $this->pending->getId());
        $form = $crawler->selectButton('Save')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/users');
        self::assertQueuedEmailCount(0);
    }

    public function testEnablingNeedsAValidToken(): void
    {
        $this->enable($this->pending, 'wrong');

        self::assertResponseStatusCodeSame(403);
        self::assertQueuedEmailCount(0);
    }

    public function testAnAccountWithAnUnconfirmedAddressCannotBeEnabled(): void
    {
        // Il pulsante non compare per gli indirizzi non confermati: il token si prende prima di annullare la conferma.
        $this->unconfirmed->verifyEmail(EntityFactory::now());
        $this->entityManager->flush();
        $token = $this->tokenFromTheList($this->unconfirmed);
        new ReflectionProperty(User::class, 'emailVerifiedAt')->setValue($this->unconfirmed, null);
        $this->entityManager->flush();

        $this->client->request('POST', '/admin/users/' . $this->unconfirmed->getId() . '/enable', ['_token' => $token], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'has not confirmed the email address yet');
        self::assertQueuedEmailCount(0);

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'unconfirmed@example.org']) ?? self::fail('user lost');
        self::assertFalse($user->isActive());
    }

    public function testTheFormDoesNotEnableAnUnconfirmedAccountEither(): void
    {
        $crawler = $this->client->request('GET', '/admin/users/' . $this->unconfirmed->getId());
        $form = $crawler->selectButton('Save')->form();
        $active = $form['user_admin[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->tick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'has not confirmed the email address yet');
        self::assertQueuedEmailCount(0);
    }

    private function tokenFromTheList(?User $user = null): string
    {
        $user ??= $this->pending;

        return $this->client->request('GET', '/admin/users')->filter('form[action$="/' . $user->getId() . '/enable"] input[name="_token"]')->attr('value') ?? self::fail('no enable form');
    }

    private function enable(User $user, string $token): void
    {
        $this->client->request('POST', '/admin/users/' . $user->getId() . '/enable', ['_token' => $token], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
