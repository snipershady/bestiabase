<?php

declare(strict_types=1);

namespace App\Service\Blast;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Directory di lavoro di un job (`var/blast/jobs/{uuid}`): input FASTA, database caricati,
 * output XML. È fuori dalla document root e non viene mai servita dal web server.
 */
final readonly class JobWorkspace
{
    public const string QUERY_FILE = 'query.fa';

    public const string SUBJECT_FILE = 'subject.fa';

    public const string SUBJECT_DATABASE = 'subject';

    public const string OUTPUT_FILE = 'output.xml';

    private string $jobsDirectory;

    public function __construct(
        #[Autowire('%env(resolve:BLAST_VAR_DIR)%')]
        string $varDirectory,
        private Filesystem $filesystem,
    ) {
        $this->jobsDirectory = rtrim($varDirectory, '/') . '/jobs';
    }

    /**
     * Crea (se serve) e restituisce la directory del job.
     */
    public function prepare(Uuid $jobId): string
    {
        $directory = $this->directory($jobId);
        $this->filesystem->mkdir($directory, 0o750);

        return $directory;
    }

    public function directory(Uuid $jobId): string
    {
        return $this->jobsDirectory . '/' . $jobId->toRfc4122();
    }

    public function path(Uuid $jobId, string $file): string
    {
        return $this->directory($jobId) . '/' . $file;
    }

    public function write(Uuid $jobId, string $file, string $content): string
    {
        $path = $this->path($jobId, $file);
        $this->filesystem->dumpFile($path, $content);

        return $path;
    }

    public function remove(Uuid $jobId): void
    {
        $this->filesystem->remove($this->directory($jobId));
    }
}
