<?php

namespace App\Enums;

/** PRD §5.1 `assets.condition` / daiku_schema.sql ENUM('GOOD','FAIR','DAMAGED'). */
enum AssetCondition: string
{
    case Good = 'GOOD';
    case Fair = 'FAIR';
    case Damaged = 'DAMAGED';
}
