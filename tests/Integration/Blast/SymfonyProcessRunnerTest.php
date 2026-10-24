<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blast;

use App\Blast\Process\ProcessResult;
use App\Blast\Process\SymfonyProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyProcessRunner::class)]
#[CoversClass(ProcessResult::class)]
final class SymfonyProcessRunnerTest extends TestCase
{
    public function testArgumentsAreNeverInterpretedByAShell(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'echo $argv[1];', '; rm -rf / $(id)'], sys_get_temp_dir(), 10.0);

        self::assertSame(0, $result->exitCode);
        self::assertSame('; rm -rf / $(id)', $result->output);
    }

    public function testExitCodeAndErrorOutputAreCaptured(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);'], sys_get_temp_dir(), 10.0);

        self::assertNotSame(0, $result->exitCode);
        self::assertSame(3, $result->exitCode);
        self::assertSame('boom', $result->errorOutput);
        self::assertFalse($result->timedOut);
    }

    public function testWorkingDirectoryIsUsed(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'echo getcwd();'], sys_get_temp_dir(), 10.0);

        self::assertSame(realpath(sys_get_temp_dir()), $result->output);
    }

    public function testTimeoutStopsTheProcess(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'echo "started"; flush(); sleep(5);'], sys_get_temp_dir(), 0.5);

        self::assertTrue($result->timedOut);
        self::assertNotSame(0, $result->exitCode);
    }

    public function testEnvironmentIsAddedToTheInheritedOne(): void
    {
        $result = new SymfonyProcessRunner()->run([\PHP_BINARY, '-r', 'echo getenv("BLAST_USAGE_REPORT"), "|", getenv("PATH") === false ? "no-path" : "path";'], sys_get_temp_dir(), 10.0, ['BLAST_USAGE_REPORT' => 'false']);

        self::assertSame('false|path', $result->output);
    }
}
