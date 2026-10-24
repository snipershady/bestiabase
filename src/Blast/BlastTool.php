<?php

declare(strict_types=1);

namespace App\Blast;

/**
 * Binari BLAST+ di servizio (i programmi di ricerca sono in {@see \App\Enum\BlastProgram}).
 */
enum BlastTool: string
{
    case MakeBlastDb = 'makeblastdb';
    case BlastDbCmd = 'blastdbcmd';
}
