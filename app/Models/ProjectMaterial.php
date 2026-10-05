<?php

namespace App\Models;

use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectMaterialSource;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.8 "Kebutuhan Material Proyek", extended in Sprint 11 Sub 3: a
 * line has a source (GUDANG / PEMBELIAN / CUSTOM) and a lifecycle —
 * received → used → leftover settled (returned to stock, wasted, or
 * handed to the client). The quantities and `cost_total` are not
 * fillable: they only move through ProjectMaterialService / StockService,
 * inside a transaction holding a lock on this row.
 */
class ProjectMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'source',
        'material_id',
        'custom_name',
        'custom_spec',
        'unit_id',
        'unit_price',
        'vendor_id',
        'request_status',
        'request_channel',
        'submitted_at',
        'request_reason',
        'photo_link',
        'requested_by',
        'requested_snapshot',
        'qty_planned',
    ];

    /** Sub 4 — who raised an out-of-catalog request: PM/Estimator (straight to Logistics) or a Tukang (PM first). */
    public const CHANNEL_TIM = 'TIM';

    public const CHANNEL_TUKANG = 'TUKANG';

    /** Sub 4 — Logistics' four decisions on a request (decision #13). */
    public const DECISION_PAKAI_KATALOG = 'PAKAI_KATALOG';

    public const DECISION_DAFTAR_KATALOG = 'DAFTAR_KATALOG';

    public const DECISION_CUSTOM = 'CUSTOM';

    public const DECISION_TOLAK = 'TOLAK';

    public const DECISIONS = [self::DECISION_PAKAI_KATALOG, self::DECISION_DAFTAR_KATALOG, self::DECISION_CUSTOM, self::DECISION_TOLAK];

    protected $appends = ['display_name', 'leftover'];

    protected function casts(): array
    {
        return [
            'source' => ProjectMaterialSource::class,
            'request_status' => MaterialRequestStatus::class,
            'unit_price' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'qty_planned' => 'float',
            'qty_received' => 'float',
            'qty_used' => 'float',
            'qty_returned' => 'float',
            'qty_wasted' => 'float',
            'qty_handed_over' => 'float',
            'requested_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'pm_reviewed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** SQL twin of leftover() — for "which lines still block completion" queries. */
    public const LEFTOVER_SQL = 'qty_received - qty_used - qty_returned - qty_wasted - qty_handed_over';

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function pmReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_reviewed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Catalog name, or the custom item's own name and spec. */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->material_id
            ? (string) $this->material?->name
            : trim($this->custom_name.($this->custom_spec ? " ({$this->custom_spec})" : '')));
    }

    /** received − used − returned − wasted − handed over (decision #12). */
    protected function leftover(): Attribute
    {
        return Attribute::get(fn (): float => Quantity::fromHundredths($this->leftoverHundredths()));
    }

    public function leftoverHundredths(): int
    {
        return Quantity::toHundredths($this->qty_received)
            - Quantity::toHundredths($this->qty_used)
            - Quantity::toHundredths($this->qty_returned)
            - Quantity::toHundredths($this->qty_wasted)
            - Quantity::toHundredths($this->qty_handed_over);
    }

    /** "2 lbr" — for validation and notification messages. */
    public function quantityLabel(float|int|string $qty): string
    {
        return Quantity::format($qty).' '.($this->unit?->code ?? '');
    }

    /** Lines whose leftover hasn't been settled — they keep the project from COMPLETED (decision #8). */
    public function scopeWithLeftover(Builder $query): Builder
    {
        return $query->whereRaw('('.self::LEFTOVER_SQL.') > 0.001');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('request_status', MaterialRequestStatus::Disetujui->value);
    }

    /** Requests nobody has decided yet (MENUNGGU_PM / DIAJUKAN) — they also block COMPLETED (Sub 4). */
    public function scopePendingRequest(Builder $query): Builder
    {
        return $query->whereIn('request_status', [MaterialRequestStatus::MenungguPm->value, MaterialRequestStatus::Diajukan->value]);
    }

    /**
     * Which requests a user may list: Logistics/CEO everything; a PM /
     * Asisten PM the projects they manage (Sprint 12 #22); a Tukang (and
     * an Estimator) what they asked for. Shared by the Pengajuan Barang
     * page and the "Perlu Tindakan" queue (Sprint 13).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['LOGISTICS', 'CEO', 'SUPERADMIN'])) {
            return $query;
        }

        if ($user->hasAnyRole(['PM', 'ASISTEN_PM'])) {
            return $query->whereHas('project', fn (Builder $project) => $project->managedBy($user));
        }

        return $query->where('requested_by', $user->id);
    }

    /** Lines that went through a request (anything a person asked for rather than planned from the catalog). */
    public function scopeRequested(Builder $query): Builder
    {
        return $query->whereNotNull('request_channel');
    }
}
