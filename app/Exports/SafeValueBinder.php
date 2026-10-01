<?php

namespace App\Exports;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Global value binder for every export (config/excel.php). User-typed text
 * — transaction descriptions, project/material/asset names — must never
 * become a live formula when Finance opens the workbook (CSV/formula
 * injection: `=HYPERLINK(...)`, DDE payloads). Strings that Excel would
 * treat as a formula are written as plain text; everything else keeps the
 * default typing so numbers and dates still format.
 */
class SafeValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
