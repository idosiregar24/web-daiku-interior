<?php

namespace App\Enums;

/** Sprint 24 D2 — Gambar Kerja: waiting for the Kepala Desain → being drawn → final ("Gambar Kerja Selesai"). */
enum WorkingDrawingStatus: string
{
    case MenungguPenugasan = 'MENUNGGU_PENUGASAN';
    case Dikerjakan = 'DIKERJAKAN';
    case Selesai = 'SELESAI';
}
