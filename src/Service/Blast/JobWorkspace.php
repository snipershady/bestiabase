<?php

declare(strict_types=1);

namespace App\Service\Blast;

use FilesystemIterator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
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

    public const string OUTPUT_ARCHIVE = 'output.xml.gz';

    private const int CHUNK_BYTES = 1_048_576;

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

    /**
     * Chiude il workspace di un job concluso: l'XML di BLAST+ resta compresso (di solito 10-20 volte più
     * piccolo) e gli input FASTA, già salvati nel database del job, vengono eliminati.
     *
     * @throws IOException se l'XML non si può comprimere; in quel caso l'originale non viene toccato
     */
    public function archive(Uuid $jobId): void
    {
        $this->compressOutput($jobId);
        $this->removeInputs($jobId);
    }

    /**
     * Path del file con l'output XML (compresso o, per i job precedenti all'archiviazione, in chiaro),
     * oppure null se il workspace è stato ripulito. Entrambi si leggono con `compress.zlib://`.
     */
    public function output(Uuid $jobId): ?string
    {
        foreach ([self::OUTPUT_ARCHIVE, self::OUTPUT_FILE] as $file) {
            $path = $this->path($jobId, $file);

            if ($this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function remove(Uuid $jobId): void
    {
        $this->filesystem->remove($this->directory($jobId));
    }

    private function compressOutput(Uuid $jobId): void
    {
        $source = $this->path($jobId, self::OUTPUT_FILE);

        if (!$this->filesystem->exists($source)) {
            return;
        }

        $target = $this->path($jobId, self::OUTPUT_ARCHIVE);
        $temporary = $target . '.tmp';

        // Il fallimento si gestisce qui sotto con un'eccezione di dominio: niente warning di PHP.
        $input = @fopen($source, 'r');
        $output = @gzopen($temporary, 'wb6');

        if ($input === false || $output === false) {
            throw new IOException(\sprintf('Cannot compress "%s".', $source), 0, null, $source);
        }

        while (!feof($input)) {
            gzwrite($output, (string) fread($input, self::CHUNK_BYTES));
        }

        fclose($input);
        gzclose($output);

        $this->filesystem->rename($temporary, $target, true);
        $this->filesystem->remove($source);
    }

    private function removeInputs(Uuid $jobId): void
    {
        $directory = $this->directory($jobId);

        if (!$this->filesystem->exists($directory)) {
            return;
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS) as $name => $pathname) {
            if ($name === self::QUERY_FILE || str_starts_with($name, self::SUBJECT_DATABASE)) {
                $this->filesystem->remove($directory . '/' . $name);
            }
        }
    }
}
