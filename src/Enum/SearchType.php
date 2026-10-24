<?php

declare(strict_types=1);

namespace App\Enum;

enum SearchType: string
{
    case Basic = 'basic';
    case Advanced = 'advanced';
}
