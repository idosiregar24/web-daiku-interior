<?php

namespace App\Enums;

/** SDM (Sprint 10, decision #3) — HR requests, the CEO decides once. */
enum SalaryChangeStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
