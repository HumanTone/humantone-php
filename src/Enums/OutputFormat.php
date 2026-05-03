<?php

declare(strict_types=1);

namespace HumanTone\Enums;

enum OutputFormat: string
{
    case Text = 'text';
    case Html = 'html';
    case Markdown = 'markdown';
}
