<?php

namespace App\Enums;

/** PRD §4.4 + §5.1. */
enum MilestoneStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case QaWaiting = 'QA_WAITING';
    case Completed = 'COMPLETED';
    case Overdue = 'OVERDUE';
}
