<?php

namespace App\Models;

use App\Enums\LeadSurveyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 12 decision #3 — a site survey of a lead (may repeat). Status
 * moves only through LeadService; an outside-Pekanbaru survey reaches
 * SIAP only via LeadService::markSurveyReady() (Finance verification).
 */
class LeadSurvey extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'sequence',
        'scheduled_at',
        'address',
        'maps_url',
        'is_outside_pekanbaru',
        'quotation_id',
        'status',
        'result_note',
        'cancel_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadSurveyStatus::class,
            'scheduled_at' => 'datetime',
            'is_outside_pekanbaru' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
