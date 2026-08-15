<?php

namespace App\Enums;

/** PRD §4.5 + §5.1. */
enum TaskStatus: string
{
    case Pending = 'PENDING';
    case OnProgress = 'ONPROGRESS';
    case Pengecekan = 'PENGECEKAN';
    case Done = 'DONE';
    case Over = 'OVER';
}
