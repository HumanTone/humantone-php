<?php

declare(strict_types=1);

namespace HumanTone\Enums;

enum HumanizationLevel: string
{
    case Standard = 'standard';
    case Advanced = 'advanced';
    case Extreme = 'extreme';
}
