<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Service\Account\DeliverableEmail;
use App\Service\Account\DeliverableEmailValidator;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaSecret;
use App\Service\Altcha\AltchaSolved;
use App\Service\Altcha\AltchaSolvedValidator;
use App\Service\Altcha\AltchaVerifier;
use App\Tests\Support\AltchaTestSolver;
use App\Tests\Support\FakeMxResolver;
use EmailValidator\Service\EmailValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(DeliverableEmail::class)]
#[CoversClass(DeliverableEmailValidator::class)]
#[CoversClass(AltchaSolved::class)]
#[CoversClass(AltchaSolvedValidator::class)]
#[UsesClass(AltchaChallengeFactory::class)]
#[UsesClass(AltchaDifficulties::class)]
#[UsesClass(AltchaDifficulty::class)]
#[UsesClass(AltchaVerifier::class)]
#[UsesClass(AltchaSecret::class)]
final class AccountValidatorsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function emails(): iterable
    {
        yield 'deliverable' => ['lab@example.org', null];
        yield 'normalised domain' => ['  Lab@Example.ORG ', null];
        yield 'no mail server' => ['lab@' . FakeMxResolver::NO_MX_DOMAIN, 'No MX record for domain'];
        yield 'null mx' => ['lab@' . FakeMxResolver::NULL_MX_DOMAIN, 'Domain declares it does not accept email (Null MX)'];
        yield 'not an address' => ['not-an-email', 'Invalid syntax'];
        yield 'transient dns error' => ['lab@' . FakeMxResolver::DNS_DOWN_DOMAIN, null];
        yield 'empty is left to NotBlank' => ['', null];
        yield 'null is left to NotBlank' => [null, null];
    }

    #[DataProvider('emails')]
    public function testEmailAddressesMustBeDeliverable(mixed $email, ?string $reason): void
    {
        $subject = new class($email) {
            public function __construct(
                #[DeliverableEmail]
                public mixed $email,
            ) {
            }
        };

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($subject);

        if ($reason === null) {
            self::assertCount(0, $violations);

            return;
        }

        self::assertCount(1, $violations);
        self::assertSame(\sprintf('This email address cannot be used: %s.', $reason), $violations->get(0)->getMessage());
    }

    public function testASolvedChallengeSatisfiesTheConstraint(): void
    {
        $payload = new AltchaTestSolver(self::getContainer()->get(AltchaChallengeFactory::class), self::getContainer()->get(AltchaDifficulties::class))->payload(AltchaPurpose::Registration);

        self::assertCount(0, $this->validateAltcha($payload));
    }

    public function testMissingOrInvalidSolutionsAreReported(): void
    {
        foreach ([null, '', 'forged', 42] as $value) {
            $violations = $this->validateAltcha($value);

            self::assertCount(1, $violations);
            self::assertSame('The security check failed. Wait for it to complete and try again.', $violations->get(0)->getMessage());
        }
    }

    public function testValidatorsRejectForeignConstraints(): void
    {
        $emailValidator = new DeliverableEmailValidator(self::getContainer()->get(EmailValidator::class));

        $this->expectException(UnexpectedTypeException::class);

        $emailValidator->validateInContext('lab@example.org', new NotBlank(), self::createStub(ExecutionContextInterface::class));
    }

    public function testTheAltchaValidatorRejectsForeignConstraints(): void
    {
        $validator = self::getContainer()->get(AltchaSolvedValidator::class);

        $this->expectException(UnexpectedTypeException::class);

        $validator->validateInContext('payload', new NotBlank(), self::createStub(ExecutionContextInterface::class));
    }

    private function validateAltcha(mixed $value): ConstraintViolationListInterface
    {
        $subject = new class($value) {
            public function __construct(
                #[AltchaSolved(AltchaPurpose::Registration)]
                public mixed $altcha,
            ) {
            }
        };

        return self::getContainer()->get(ValidatorInterface::class)->validate($subject);
    }
}
