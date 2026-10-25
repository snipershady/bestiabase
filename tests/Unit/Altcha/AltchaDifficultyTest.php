<?php

declare(strict_types=1);

namespace App\Tests\Unit\Altcha;

use AltchaOrg\Altcha\HmacAlgorithm;
use App\Service\Altcha\AltchaDifficulties;
use App\Service\Altcha\AltchaDifficulty;
use App\Service\Altcha\AltchaPurpose;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AltchaDifficulty::class)]
#[CoversClass(AltchaDifficulties::class)]
final class AltchaDifficultyTest extends TestCase
{
    /**
     * @return iterable<string, array{AltchaDifficulty, int, int, HmacAlgorithm}>
     */
    public static function levels(): iterable
    {
        yield 'low' => [AltchaDifficulty::Low, 1_000, 1, HmacAlgorithm::SHA256];
        yield 'medium' => [AltchaDifficulty::Medium, 5_000, 1, HmacAlgorithm::SHA384];
        yield 'medium high' => [AltchaDifficulty::MediumHigh, 10_000, 1, HmacAlgorithm::SHA512];
        yield 'high' => [AltchaDifficulty::High, 50_000, 1, HmacAlgorithm::SHA512];
        yield 'very high' => [AltchaDifficulty::VeryHigh, 200_000, 3, HmacAlgorithm::SHA512];
    }

    #[DataProvider('levels')]
    public function testEachLevelHasItsOwnCost(AltchaDifficulty $difficulty, int $cost, int $prefixLength, HmacAlgorithm $algorithm): void
    {
        self::assertSame($cost, $difficulty->cost());
        self::assertSame($prefixLength, $difficulty->keyPrefixLength());
        self::assertSame($algorithm, $difficulty->hmacAlgorithm());
    }

    public function testEveryPurposeHasItsConfiguredDifficulty(): void
    {
        $difficulties = new AltchaDifficulties(AltchaDifficulty::Low, AltchaDifficulty::Medium, AltchaDifficulty::High, AltchaDifficulty::VeryHigh);

        self::assertSame(AltchaDifficulty::Low, $difficulties->forPurpose(AltchaPurpose::Login));
        self::assertSame(AltchaDifficulty::Medium, $difficulties->forPurpose(AltchaPurpose::Registration));
        self::assertSame(AltchaDifficulty::High, $difficulties->forPurpose(AltchaPurpose::SecurityCheck));
        self::assertSame(AltchaDifficulty::VeryHigh, $difficulties->forPurpose(AltchaPurpose::Trap));
    }
}
