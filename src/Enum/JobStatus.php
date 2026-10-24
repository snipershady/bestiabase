<?php

declare(strict_types=1);

namespace App\Enum;

enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Running => false,
            self::Finished, self::Failed, self::Cancelled => true,
        };
    }
}
