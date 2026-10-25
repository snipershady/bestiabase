<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use AltchaOrg\Altcha\HmacAlgorithm;

/**
 * Livelli di difficoltà della proof of work (PBKDF2). Il lavoro medio per risolverla è
 * `cost` iterazioni × 256^keyPrefixLength tentativi: l'operatore li sceglie per ogni uso con le
 * variabili ALTCHA_*_DIFFICULTY, senza toccare il codice.
 */
enum AltchaDifficulty: string
{
    case Low = 'low';
    case Medium = 'medium';
    case MediumHigh = 'medium_high';
    case High = 'high';
    case VeryHigh = 'very_high';

    public function cost(): int
    {
        return match ($this) {
            self::Low => 1_000,
            self::Medium => 5_000,
            self::MediumHigh => 10_000,
            self::High => 50_000,
            self::VeryHigh => 200_000,
        };
    }

    /**
     * Byte del prefisso che la chiave derivata deve avere: ogni byte moltiplica per 256 i tentativi.
     */
    public function keyPrefixLength(): int
    {
        return match ($this) {
            self::Low, self::Medium, self::MediumHigh, self::High => 1,
            self::VeryHigh => 3,
        };
    }

    public function hmacAlgorithm(): HmacAlgorithm
    {
        return match ($this) {
            self::Low => HmacAlgorithm::SHA256,
            self::Medium => HmacAlgorithm::SHA384,
            self::MediumHigh, self::High, self::VeryHigh => HmacAlgorithm::SHA512,
        };
    }
}
