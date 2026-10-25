<?php

declare(strict_types=1);

namespace App\Tests\Integration\Altcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Service\Altcha\AltchaChallengeFactory;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaSecret;
use App\Service\Altcha\AltchaVerifier;
use App\Tests\Support\AltchaTestSolver;
use DateTimeImmutable;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(AltchaVerifier::class)]
#[CoversClass(AltchaChallengeFactory::class)]
#[CoversClass(AltchaSecret::class)]
#[UsesClass(AltchaDifficulty::class)]
#[UsesClass(AltchaDifficulties::class)]
final class AltchaVerifierTest extends KernelTestCase
{
    private const string KEY = 'test-only-altcha-key';

    public function testASolvedChallengeVerifiesOnlyOnce(): void
    {
        $payload = $this->payload(AltchaPurpose::Login);

        self::assertTrue($this->verifier()->verify($payload, AltchaPurpose::Login));
        self::assertFalse($this->verifier()->verify($payload, AltchaPurpose::Login), 'a solution cannot be replayed');
    }

    public function testASolutionIsOnlyValidForThePurposeItWasIssuedFor(): void
    {
        self::assertFalse($this->verifier()->verify($this->payload(AltchaPurpose::Login), AltchaPurpose::Registration));
    }

    public function testMissingAndMalformedPayloadsAreRejected(): void
    {
        $verifier = $this->verifier();

        self::assertFalse($verifier->verify(null, AltchaPurpose::Login));
        self::assertFalse($verifier->verify('   ', AltchaPurpose::Login));
        self::assertFalse($verifier->verify('not base64 at all!', AltchaPurpose::Login));
        self::assertFalse($verifier->verify(base64_encode('not json'), AltchaPurpose::Login));
    }

    public function testAWrongSolutionIsRejected(): void
    {
        $decoded = Payload::fromBase64($this->payload(AltchaPurpose::Login));
        $forged = new Payload($decoded->challenge, new Solution($decoded->solution->counter + 1, $decoded->solution->derivedKey));

        self::assertFalse($this->verifier()->verify($forged->toBase64(), AltchaPurpose::Login));
    }

    public function testAChallengeSignedWithAnotherKeyIsRejected(): void
    {
        $factory = new AltchaChallengeFactory(new AltchaSecret('another-key'), $this->difficulties(AltchaDifficulty::Low), self::getContainer()->get(ClockInterface::class));

        self::assertFalse($this->verifier()->verify($this->solve($factory, AltchaPurpose::Login, AltchaDifficulty::Low), AltchaPurpose::Login));
    }

    public function testACheaperChallengeCannotStandInForTheExpectedOne(): void
    {
        $costlier = new AltchaChallengeFactory(new AltchaSecret(self::KEY), $this->difficulties(AltchaDifficulty::Medium), self::getContainer()->get(ClockInterface::class));

        self::assertFalse(
            $this->verifier()->verify($this->solve($costlier, AltchaPurpose::Login, AltchaDifficulty::Medium), AltchaPurpose::Login),
            'the configured difficulty is "low": a challenge with another cost is not accepted',
        );
    }

    public function testAnExpiredChallengeIsRejected(): void
    {
        $clock = new class implements ClockInterface {
            #[Override]
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('-2 hours');
            }
        };
        $factory = new AltchaChallengeFactory(new AltchaSecret(self::KEY), $this->difficulties(AltchaDifficulty::Low), $clock);

        self::assertFalse($this->verifier()->verify($this->solve($factory, AltchaPurpose::Login, AltchaDifficulty::Low), AltchaPurpose::Login));
    }

    public function testChallengesCarryThePurposeAndAreSigned(): void
    {
        $challenge = AltchaTestSolver::challenge(self::getContainer()->get(AltchaChallengeFactory::class)->createJson(AltchaPurpose::Trap));

        self::assertSame(['purpose' => 'trap'], $challenge->parameters->data);
        self::assertSame(1_000, $challenge->parameters->cost);
        self::assertNotNull($challenge->signature);
    }

    public function testTheRequestedPrefixLengthIsHonouredAtEveryLevel(): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);

        foreach (AltchaDifficulty::cases() as $difficulty) {
            $factory = new AltchaChallengeFactory(new AltchaSecret(self::KEY), $this->difficulties($difficulty), $clock);
            $challenge = AltchaTestSolver::challenge($factory->createJson(AltchaPurpose::Login));

            self::assertSame(2 * $difficulty->keyPrefixLength(), \strlen($challenge->parameters->keyPrefix), $difficulty->value);
        }
    }

    public function testTheExampleKeyIsNeverAccepted(): void
    {
        foreach (['', '   ', AltchaSecret::PLACEHOLDER] as $key) {
            try {
                new AltchaSecret($key);
                self::fail(\sprintf('The key "%s" should be refused.', $key));
            } catch (LogicException $exception) {
                self::assertStringContainsString('ALTCHA_KEY is not set', $exception->getMessage());
            }
        }

        self::assertSame('a-real-key', new AltchaSecret('a-real-key')->value);
    }

    private function verifier(): AltchaVerifier
    {
        return self::getContainer()->get(AltchaVerifier::class);
    }

    private function payload(AltchaPurpose $purpose): string
    {
        return new AltchaTestSolver(self::getContainer()->get(AltchaChallengeFactory::class), self::getContainer()->get(AltchaDifficulties::class))->payload($purpose);
    }

    private function difficulties(AltchaDifficulty $difficulty): AltchaDifficulties
    {
        return new AltchaDifficulties($difficulty, $difficulty, $difficulty, $difficulty);
    }

    private function solve(AltchaChallengeFactory $factory, AltchaPurpose $purpose, AltchaDifficulty $difficulty): string
    {
        $challenge = AltchaTestSolver::challenge($factory->createJson($purpose));
        $solution = new Altcha()->solveChallenge(new SolveChallengeOptions($challenge, new Pbkdf2($difficulty->hmacAlgorithm())));
        self::assertInstanceOf(Solution::class, $solution);

        return new Payload($challenge, $solution)->toBase64();
    }
}
