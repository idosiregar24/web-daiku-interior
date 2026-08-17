<?php

namespace App\Enums;

/** PRD §4.7 — the coarse in/out split; `FinanceCategory` carries the finer classification. */
enum FinanceTransactionType: string
{
    case Income = 'PEMASUKAN';
    case Expense = 'PENGELUARAN';
}
