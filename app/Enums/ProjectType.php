<?php

namespace App\Enums;

/**
 * PRD §4.2 "Jenis Project" — shared between Design and Project (both
 * daiku_schema.sql `designs.jenis_project` and `projects.jenis_project`
 * use the same set).
 */
enum ProjectType: string
{
    case Toko = 'TOKO';
    case Cafe = 'CAFE';
    case Renovasi = 'RENOVASI';
    case KamarSet = 'KAMAR_SET';
    case KitchenSet = 'KITCHEN_SET';
    case Kantor = 'KANTOR';
    case Arsitektural = 'ARSITEKTURAL';
    case RuangTamuTv = 'RUANG_TAMU_TV';
    case RetailToko = 'RETAIL_TOKO';
    case Lainnya = 'LAINNYA';
}
