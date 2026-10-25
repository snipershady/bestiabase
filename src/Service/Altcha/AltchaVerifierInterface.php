<?php

declare(strict_types=1);

namespace App\Service\Altcha;

interface AltchaVerifierInterface
{
    /**
     * @param string|null $payload il campo `altcha` inviato dal widget (base64: sfida + soluzione)
     */
    public function verify(?string $payload, AltchaPurpose $purpose): bool;
}
