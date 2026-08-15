<?php

namespace App\Enums;

/** PRD §4.4 + §5.1, confirmed against daiku_schema.sql `projects.status`. */
enum ProjectStatus: string
{
    case Active = 'ACTIVE';
    case OnHold = 'ON_HOLD';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
