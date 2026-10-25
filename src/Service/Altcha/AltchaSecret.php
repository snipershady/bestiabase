<?php

declare(strict_types=1);

namespace App\Service\Altcha;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La chiave che firma le sfide. Se resta quella di esempio di `.env` le sfide sarebbero falsificabili
 * da chiunque legga il repository: meglio che l'applicazione si rifiuti di funzionare.
 */
final readonly class AltchaSecret
{
    public const string PLACEHOLDER = '!ChangeMe!';

    public string $value;

    /**
     * @throws LogicException se la chiave è vuota o è quella di esempio
     */
    public function __construct(
        #[Autowire(env: 'ALTCHA_KEY')]
        string $value,
    ) {
        if (trim($value) === '' || $value === self::PLACEHOLDER) {
            throw new LogicException('ALTCHA_KEY is not set: put a random string in .env.local (or the Symfony vault).');
        }

        $this->value = $value;
    }
}
