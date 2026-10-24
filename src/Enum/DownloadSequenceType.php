<?php

declare(strict_types=1);

namespace App\Enum;

enum DownloadSequenceType: string
{
    /** Sequenza completa del soggetto. */
    case Entire = 'entire';

    /** Solo la regione del soggetto allineata alla query. */
    case Mapping = 'mapping';
}
