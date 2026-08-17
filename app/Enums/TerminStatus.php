<?php

namespace App\Enums;

/** PRD §4.4/§4.7/§6.4 + daiku_schema.sql `termins.status`. */
enum TerminStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Invoiced = 'INVOICED';
    case Paid = 'PAID';
    case Overdue = 'OVERDUE';
}
