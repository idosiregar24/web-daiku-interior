<?php

namespace App\Enums;

/** PRD §4.5. */
enum TaskPriority: string
{
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case Low = 'LOW';
}
