<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\BlastProgram;
use App\Service\Blast\BlastBinaryLocator;
use App\Service\Blast\BlastTool;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Directory con eseguibili fittizi che si chiamano come i binari BLAST+.
 */
final readonly class FakeBlastInstallation
{
    public TemporaryDirectory $directory;

    public function __construct()
    {
        $this->directory = new TemporaryDirectory();
        $filesystem = new Filesystem();
        $names = [
            ...array_map(static fn (BlastProgram $program): string => $program->value, BlastProgram::cases()),
            ...array_map(static fn (BlastTool $tool): string => $tool->value, BlastTool::cases()),
        ];

        foreach ($names as $name) {
            $filesystem->dumpFile($this->binary($name), "#!/bin/sh\nexit 0\n");
            $filesystem->chmod($this->binary($name), 0o755);
        }
    }

    public function binary(string $name): string
    {
        return $this->directory->path . '/' . $name;
    }

    public function locator(): BlastBinaryLocator
    {
        return new BlastBinaryLocator($this->directory->path . '/');
    }

    public function remove(): void
    {
        $this->directory->remove();
    }
}
