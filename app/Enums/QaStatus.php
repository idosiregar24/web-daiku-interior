<?php

namespace App\Enums;

/** PRD §4.6 — matches TS `QAStatus` in resources/js/types/index.d.ts exactly. */
enum QaStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
