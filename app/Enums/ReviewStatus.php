<?php

namespace App\Enums;

/**
 * SDM (Sprint 10, §3.4) — DRAFT → SUBMITTED (HR) → APPROVED (CEO, or
 * returned to DRAFT) → ACKNOWLEDGED (employee). Locked from APPROVED on.
 */
enum ReviewStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Acknowledged = 'ACKNOWLEDGED';
}
