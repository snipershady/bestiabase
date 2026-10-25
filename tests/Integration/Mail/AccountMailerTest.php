<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Entity\User;
use App\Service\Mail\AccountMailer;
use App\Tests\Support\EntityFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

#[CoversClass(AccountMailer::class)]
#[UsesClass(User::class)]
final class AccountMailerTest extends KernelTestCase
{
    public function testPasswordResetEmailCarriesTheAbsoluteLink(): void
    {
        $token = new ResetPasswordToken('signed-token', new DateTimeImmutable('2026-10-25 11:00:00 UTC'), 1_761_390_000);

        self::getContainer()->get(AccountMailer::class)->sendPasswordReset(EntityFactory::user('lab@example.org', 'lab'), $token);

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Reset your BestiaBase password', $email->getSubject());
        self::assertSame('no-reply@bestiabase.example', $email->getFrom()[0]->getAddress());
        self::assertSame('lab@example.org', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('/reset-password/reset/signed-token', (string) $email->getTextBody());
        self::assertStringContainsString('/reset-password/reset/signed-token', (string) $email->getHtmlBody());
        self::assertStringContainsString('2026-10-25 11:00 UTC', (string) $email->getTextBody());
    }

    public function testAccountEnabledEmailLinksToTheSignInPage(): void
    {
        self::getContainer()->get(AccountMailer::class)->sendAccountEnabled(EntityFactory::user('lab@example.org', 'lab'));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Your BestiaBase account is enabled', $email->getSubject());
        self::assertStringContainsString('/login', (string) $email->getTextBody());
    }
}
