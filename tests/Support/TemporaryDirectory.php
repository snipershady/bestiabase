<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Directory temporanea univoca per un test, da rimuovere nel tearDown.
 */
final readonly class TemporaryDirectory
{
    public string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/bestiabase-test-' . bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->path);
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }
}
