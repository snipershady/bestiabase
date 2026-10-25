<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Controller\AccountController;
use App\Entity\User;
use App\EventSubscriber\NotFoundSubscriber;
use App\Repository\BlastDatabaseRepository;
use App\Repository\UserRepository;
use App\Security\BlastDatabaseVoter;
use App\Security\BlastJobVoter;
use App\Security\RecordLastLoginListener;
use App\Security\SecurityHeadersListener;
use App\Service\Account\ChangePasswordData;
use App\Service\Account\ChangePasswordType;
use App\Service\Account\NewPasswordType;
use App\Service\SecurityCheck\SecurityCheckCookie;
use App\Tests\Support\EntityFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Twig\SecurityExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(AccountController::class)]
#[CoversClass(ChangePasswordData::class)]
#[CoversClass(ChangePasswordType::class)]
#[CoversClass(NewPasswordType::class)]
#[UsesClass(User::class)]
#[UsesClass(NotFoundSubscriber::class)]
#[UsesClass(BlastDatabaseRepository::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(SecurityCheckCookie::class)]
#[UsesClass(BlastDatabaseVoter::class)]
#[UsesClass(BlastJobVoter::class)]
#[UsesClass(RecordLastLoginListener::class)]
#[UsesClass(SecurityHeadersListener::class)]
#[UsesClass(SecurityExtension::class)]
final class ChangePasswordTest extends FunctionalTestCase
{
    private User $user;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = EntityFactory::user('lab@example.org', 'lab');
        $this->user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'the old password'));

        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testAnonymousVisitorsMustSignIn(): void
    {
        $this->client->request('GET', '/account/password');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testThePasswordChangesAndTheSessionSurvives(): void
    {
        $this->client->loginUser($this->user);
        $this->submit('the old password', 'the brand new password', 'the brand new password');

        self::assertResponseRedirects('/account/password');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', 'Your password has been changed.');
        self::assertSelectorTextContains('nav summary', 'lab');

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'lab@example.org']) ?? self::fail('user lost');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'the brand new password'));
    }

    public function testInvalidChangesAreRejected(): void
    {
        $this->client->loginUser($this->user);

        $cases = [
            'The current password is not correct.' => ['wrong password', 'the brand new password', 'the brand new password'],
            'The new password must differ from the current one.' => ['the old password', 'the old password', 'the old password'],
            'This value is too short.' => ['the old password', 'short', 'short'],
            'The passwords do not match.' => ['the old password', 'the brand new password', 'something different'],
        ];

        foreach ($cases as $message => [$current, $first, $second]) {
            $this->submit($current, $first, $second);

            self::assertResponseStatusCodeSame(422, $message);
            self::assertSelectorTextContains('form', $message);
        }
    }

    public function testAccountsNotYetEnabledCanChangeTheirPassword(): void
    {
        $this->user->deactivate();
        $this->entityManager->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/account/password');

        self::assertResponseIsSuccessful();
    }

    private function submit(string $current, string $first, string $second): void
    {
        $form = $this->client->request('GET', '/account/password')->selectButton('Change password')->form();
        $form['change_password[currentPassword]'] = $current;
        $form['change_password[password][first]'] = $first;
        $form['change_password[password][second]'] = $second;
        $this->client->submit($form);
    }
}
