<?php

namespace App\Services;

use App\Enums\KpiDirection;
use App\Enums\KpiIndicatorSource;
use App\Enums\KpiPeriodStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\KpiIndicator;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use App\Models\KpiTemplate;
use App\Models\Position;
use App\Models\User;
use App\Services\Kpi\KpiMetricRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SDM (Sprint 10, §3.3) — monthly KPI.
 *
 * Flow: HR keeps one template per position (indicators, weights total
 * 100). A month (`kpi_periods`) is OPEN → `compute()` snapshots every
 * eligible employee's indicators into `kpi_scores` and fills AUTO actuals
 * from operational data (KpiMetricRegistry, needs the employee's linked
 * account) → HR types MANUAL actuals → `close()` locks the month for good.
 *
 * Scoring per row: HIGHER_BETTER = actual ÷ target × 100, LOWER_BETTER =
 * target ÷ actual × 100 (an actual of 0 scores the maximum), clamped to
 * 0–120. A row without an actual (AUTO with nothing to measure / no
 * linked account, or MANUAL not filled yet) is unscored; its weight is
 * redistributed proportionally over the employee's scored rows that month:
 *
 *     weighted_score = score × weight ÷ Σ(weight of scored rows)
 *
 * so the month total Σ weighted_score stays on the 0–120 scale. With every
 * row scored Σ weight = 100 and this is plain `score × weight ÷ 100`.
 * A month with no scored row has no total (null).
 *
 * Employees are always taken through `Employee::hrEligible()` (decision
 * #11 — field staff never appear).
 */
class KpiService
{
    public const MAX_SCORE = 120;

    /** How many months the employee tab and the trend chart show. */
    public const HISTORY_MONTHS = 12;

    public function __construct(
        private AuditLogService $auditLogService,
        private KpiMetricRegistry $metrics,
    ) {}

    // ── Templates ─────────────────────────────────────────────────────────

    /**
     * Upsert the position's template and sync its indicators: rows sent
     * with an `id` of this template are updated, new rows created, missing
     * ones removed. Closed months keep their snapshot (kpi_scores copy the
     * indicator); an OPEN month picks the change up on its next compute.
     *
     * @param  list<array{id?: int|null, name: string, source: string, metric_key?: string|null, target: float|int|string, weight: float|int|string, direction: string}>  $indicators
     */
    public function saveTemplate(Position $position, array $indicators, User $actor): KpiTemplate
    {
        $this->validateIndicators($indicators);

        return DB::transaction(function () use ($position, $indicators, $actor) {
            $template = KpiTemplate::query()->lockForUpdate()->firstOrCreate(
                ['position_id' => $position->id],
                ['is_active' => true, 'created_by' => $actor->id],
            );

            $existing = $template->indicators()->get()->keyBy('id');
            $old = $existing->isEmpty() ? null : ['indicators' => $this->indicatorSnapshot($existing->values())];
            $kept = [];

            foreach (array_values($indicators) as $order => $row) {
                $source = KpiIndicatorSource::from($row['source']);
                $attributes = [
                    'name' => trim($row['name']),
                    'source' => $source->value,
                    'metric_key' => $source === KpiIndicatorSource::Auto ? $row['metric_key'] : null,
                    'target' => $row['target'],
                    'weight' => $row['weight'],
                    'direction' => $row['direction'],
                    'sort_order' => $order,
                ];

                $id = isset($row['id']) ? (int) $row['id'] : null;

                if ($id && $existing->has($id)) {
                    $existing[$id]->update($attributes);
                    $kept[] = $id;
                } else {
                    $kept[] = $template->indicators()->create($attributes)->id;
                }
            }

            // kpi_scores.kpi_indicator_id is nulled by the FK; the snapshot stays.
            $existing->except($kept)->each->delete();

            $template->load('indicators');

            $this->auditLogService->record(
                'hr.kpi_template_saved',
                $template,
                $old,
                ['position' => $position->name, 'indicators' => $this->indicatorSnapshot($template->indicators)],
                $actor,
            );

            return $template;
        });
    }

    /** Activate/deactivate a position's template (templates are never deleted). */
    public function toggleTemplate(Position $position, User $actor): KpiTemplate
    {
        return DB::transaction(function () use ($position, $actor) {
            $template = KpiTemplate::query()->where('position_id', $position->id)->lockForUpdate()->first();

            if (! $template) {
                throw ValidationException::withMessages(['template' => 'Jabatan ini belum punya template KPI.']);
            }

            $before = $template->is_active;
            $template->update(['is_active' => ! $before]);

            $this->auditLogService->record(
                'hr.kpi_template_toggled',
                $template,
                ['is_active' => $before],
                ['is_active' => $template->is_active, 'position' => $position->name],
                $actor,
            );

            return $template;
        });
    }

    /**
     * Same rules as SaveKpiTemplateRequest, enforced again here so any
     * caller (seeders, future imports) gets them.
     *
     * @param  array<int, array<string, mixed>>  $indicators
     */
    private function validateIndicators(array $indicators): void
    {
        if ($indicators === []) {
            throw ValidationException::withMessages(['indicators' => 'Template KPI minimal berisi 1 indikator.']);
        }

        $errors = [];
        $total = 0.0;

        foreach (array_values($indicators) as $i => $row) {
            $source = KpiIndicatorSource::tryFrom((string) ($row['source'] ?? ''));
            $metric = $row['metric_key'] ?? null;

            if (trim((string) ($row['name'] ?? '')) === '') {
                $errors["indicators.{$i}.name"] = 'Nama indikator wajib diisi.';
            }
            if (! $source) {
                $errors["indicators.{$i}.source"] = 'Sumber indikator tidak valid.';
            } elseif ($source === KpiIndicatorSource::Auto && ! $this->metrics->has($metric)) {
                $errors["indicators.{$i}.metric_key"] = 'Indikator otomatis wajib memilih metrik yang dikenal sistem.';
            } elseif ($source === KpiIndicatorSource::Manual && filled($metric)) {
                $errors["indicators.{$i}.metric_key"] = 'Indikator manual tidak memakai metrik otomatis.';
            }
            if (! KpiDirection::tryFrom((string) ($row['direction'] ?? ''))) {
                $errors["indicators.{$i}.direction"] = 'Arah penilaian tidak valid.';
            }
            if (! is_numeric($row['target'] ?? null) || (float) $row['target'] <= 0) {
                $errors["indicators.{$i}.target"] = 'Target harus lebih dari 0.';
            }
            if (! is_numeric($row['weight'] ?? null) || (float) $row['weight'] <= 0) {
                $errors["indicators.{$i}.weight"] = 'Bobot harus lebih dari 0.';
            } else {
                $total += (float) $row['weight'];
            }
        }

        if ($errors === [] && abs($total - 100) > 0.001) {
            $errors['indicators'] = 'Total bobot harus tepat 100% (sekarang '.rtrim(rtrim(number_format($total, 2, ',', ''), '0'), ',').'%).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  Collection<int, KpiIndicator>  $indicators */
    private function indicatorSnapshot(Collection $indicators): array
    {
        return $indicators->map(fn (KpiIndicator $indicator) => [
            'name' => $indicator->name,
            'source' => $indicator->source->value,
            'metric_key' => $indicator->metric_key,
            'target' => (float) $indicator->target,
            'weight' => (float) $indicator->weight,
            'direction' => $indicator->direction->value,
        ])->values()->all();
    }

    // ── Periods ───────────────────────────────────────────────────────────

    /** Open a month (`YYYY-MM`). Idempotent; future months are refused. */
    public function openPeriod(string $period, ?User $actor = null): KpiPeriod
    {
        $month = $this->parseMonth($period);

        if (! $month) {
            throw ValidationException::withMessages(['period' => 'Format periode harus YYYY-MM.']);
        }
        if ($month->gt(Carbon::today()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Periode KPI bulan yang akan datang belum bisa dibuka.']);
        }

        return DB::transaction(function () use ($month, $actor) {
            $existing = KpiPeriod::query()->where('period', $month->format('Y-m'))->first();

            if ($existing) {
                return $existing;
            }

            $created = KpiPeriod::create(['period' => $month->format('Y-m'), 'status' => KpiPeriodStatus::Open->value]);

            $this->auditLogService->record('hr.kpi_period_opened', $created, null, ['period' => $created->period], $actor);

            return $created;
        });
    }

    /**
     * (Re)build the OPEN month's score rows: one per eligible employee ×
     * indicator of their position's active template, indicator snapshotted
     * in. AUTO actuals are recomputed every time; MANUAL actuals already
     * entered are kept. Rows no longer backed by the template (indicator
     * removed, employee moved/deactivated) are dropped — only possible
     * while OPEN.
     *
     * @return array{employees: int, rows: int, autoMissing: int, manualMissing: int}
     */
    public function compute(KpiPeriod $period, User $actor): array
    {
        return DB::transaction(function () use ($period, $actor) {
            $period = $this->lockOpenPeriod($period);
            $summary = $this->rebuild($period);

            $this->auditLogService->record('hr.kpi_period_computed', $period, null, ['period' => $period->period, ...$summary], $actor);

            return $summary;
        });
    }

    /** HR fills a MANUAL actual (null clears it) of an OPEN month; the employee's month is re-scored. */
    public function updateManualScore(KpiScore $score, ?float $actual, User $actor): KpiScore
    {
        return DB::transaction(function () use ($score, $actual, $actor) {
            $this->lockOpenPeriod($score->period);
            $score = KpiScore::query()->lockForUpdate()->findOrFail($score->id);

            if ($score->source !== KpiIndicatorSource::Manual) {
                throw ValidationException::withMessages(['actual' => 'Nilai indikator otomatis dihitung sistem, tidak diisi manual.']);
            }

            $before = $score->actual === null ? null : (float) $score->actual;
            $score->update(['actual' => $actual, 'input_by' => $actor->id]);

            $this->rescore(KpiScore::query()
                ->where('kpi_period_id', $score->kpi_period_id)
                ->where('employee_id', $score->employee_id)
                ->get());

            $score->refresh();

            $this->auditLogService->record(
                'hr.kpi_score_updated',
                $score,
                ['actual' => $before],
                ['actual' => $actual, 'indicator' => $score->indicator_name, 'employee_id' => $score->employee_id, 'score' => $score->score],
                $actor,
            );

            return $score;
        });
    }

    /**
     * OPEN → CLOSED. Recomputes first so the frozen snapshot reflects the
     * latest data and template, then refuses while any MANUAL actual is
     * still empty. A month that was never computed can't be closed.
     */
    public function close(KpiPeriod $period, User $actor): KpiPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $period = $this->lockOpenPeriod($period);

            if (! $period->scores()->exists()) {
                throw ValidationException::withMessages(['period' => 'Periode ini belum pernah dihitung. Klik "Hitung" dulu sebelum menutup.']);
            }

            $summary = $this->rebuild($period);

            if ($summary['manualMissing'] > 0) {
                throw ValidationException::withMessages([
                    'period' => "Masih ada {$summary['manualMissing']} nilai indikator manual yang belum diisi. Lengkapi dulu sebelum menutup periode.",
                ]);
            }

            $period->update([
                'status' => KpiPeriodStatus::Closed->value,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ]);

            $this->auditLogService->record(
                'hr.kpi_period_closed',
                $period,
                ['status' => KpiPeriodStatus::Open->value],
                ['status' => KpiPeriodStatus::Closed->value, 'period' => $period->period, 'employees' => $summary['employees'], 'average' => $this->periodTotals($period)->avg()],
                $actor,
            );

            return $period;
        });
    }

    private function lockOpenPeriod(KpiPeriod $period): KpiPeriod
    {
        $locked = KpiPeriod::query()->lockForUpdate()->findOrFail($period->id);

        if ($locked->isClosed()) {
            throw ValidationException::withMessages(['period' => 'Periode KPI ini sudah ditutup dan tidak bisa diubah.']);
        }

        return $locked;
    }

    /** @return array{employees: int, rows: int, autoMissing: int, manualMissing: int} */
    private function rebuild(KpiPeriod $period): array
    {
        $from = $this->parseMonth($period->period);
        $to = $from->copy()->addMonthNoOverflow();

        $employees = Employee::query()
            ->hrEligible()
            ->active()
            ->where(fn (Builder $query) => $query->whereNull('join_date')->orWhere('join_date', '<', $to->toDateString()))
            ->whereHas('position.kpiTemplate', fn (Builder $query) => $query->where('is_active', true)->has('indicators'))
            ->with(['user', 'position.kpiTemplate.indicators'])
            ->get();

        $existing = KpiScore::query()->where('kpi_period_id', $period->id)->get();
        $byKey = $existing->keyBy(fn (KpiScore $score) => $score->employee_id.':'.$score->kpi_indicator_id);
        $cache = [];
        $kept = [];
        $summary = ['employees' => $employees->count(), 'rows' => 0, 'autoMissing' => 0, 'manualMissing' => 0];

        foreach ($employees as $employee) {
            $rows = collect();

            foreach ($employee->position->kpiTemplate->indicators as $indicator) {
                /** @var KpiScore $score */
                $score = $byKey->get($employee->id.':'.$indicator->id) ?? new KpiScore([
                    'kpi_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'kpi_indicator_id' => $indicator->id,
                ]);

                $wasManual = $score->exists && $score->source === KpiIndicatorSource::Manual;

                $score->fill([
                    'indicator_name' => $indicator->name,
                    'source' => $indicator->source->value,
                    'metric_key' => $indicator->metric_key,
                    'target' => $indicator->target,
                    'weight' => $indicator->weight,
                    'direction' => $indicator->direction->value,
                ]);

                if ($indicator->source === KpiIndicatorSource::Auto) {
                    $actual = null;

                    if ($employee->user) {
                        $key = $employee->user->id.':'.$indicator->metric_key;
                        $cache[$key] ??= ['value' => $this->metrics->compute($indicator->metric_key, $employee->user, $from, $to)];
                        $actual = $cache[$key]['value'];
                    }

                    $score->fill(['actual' => $actual, 'input_by' => null]);
                    $summary['autoMissing'] += $actual === null ? 1 : 0;
                } else {
                    if (! $wasManual) {
                        // Switched AUTO → MANUAL: the computed value isn't HR's input.
                        $score->fill(['actual' => null, 'input_by' => null]);
                    }

                    $summary['manualMissing'] += $score->actual === null ? 1 : 0;
                }

                $score->save();
                $rows->push($score);
                $kept[] = $score->id;
            }

            $this->rescore($rows);
            $summary['rows'] += $rows->count();
        }

        $existing->reject(fn (KpiScore $score) => in_array($score->id, $kept, true))->each->delete();

        return $summary;
    }

    /**
     * Score + weighted score of one employee's rows of one month (the
     * redistribution rule in the class docblock).
     *
     * @param  Collection<int, KpiScore>  $rows
     */
    private function rescore(Collection $rows): void
    {
        foreach ($rows as $row) {
            $row->score = $this->scoreFor(
                $row->actual === null ? null : (float) $row->actual,
                (float) $row->target,
                $row->direction,
            );
        }

        $scoredWeight = $rows->filter(fn (KpiScore $row) => $row->score !== null)->sum(fn (KpiScore $row) => (float) $row->weight);

        foreach ($rows as $row) {
            $row->weighted_score = $row->score !== null && $scoredWeight > 0
                ? round((float) $row->score * (float) $row->weight / $scoredWeight, 2)
                : null;

            if ($row->isDirty()) {
                $row->save();
            }
        }
    }

    /** One indicator's 0–120 score, or null without an actual. */
    public function scoreFor(?float $actual, float $target, KpiDirection $direction): ?float
    {
        if ($actual === null || $target <= 0) {
            return null;
        }

        if ($direction === KpiDirection::LowerBetter) {
            $raw = $actual <= 0 ? self::MAX_SCORE : $target / $actual * 100;
        } else {
            $raw = $actual / $target * 100;
        }

        return round(max(0, min(self::MAX_SCORE, $raw)), 2);
    }

    // ── Read models ───────────────────────────────────────────────────────

    /**
     * Data of the "KPI" tab for one employee (profile page and the
     * employee's own "Milik Saya" page — `$closedOnly`, decision #6).
     *
     * @return array{template: array<string, mixed>|null, periods: list<array<string, mixed>>, trend: list<array{period: string, label: string, total: float|null}>}
     */
    public function forEmployee(Employee $employee, bool $closedOnly = false): array
    {
        $employee->loadMissing('position.kpiTemplate.indicators');
        $template = $employee->position?->kpiTemplate;

        $periods = KpiPeriod::query()
            ->when($closedOnly, fn (Builder $query) => $query->closed())
            ->whereHas('scores', fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->orderByDesc('period')
            ->limit(self::HISTORY_MONTHS)
            ->with(['scores' => fn ($query) => $query->where('employee_id', $employee->id)->orderBy('id')])
            ->get();

        $rows = $periods->map(fn (KpiPeriod $period) => [
            'id' => $period->id,
            'period' => $period->period,
            'label' => $this->monthLabel($period->period),
            'status' => $period->status->value,
            'total' => $this->total($period->scores),
            'rows' => $period->scores->map(fn (KpiScore $score) => $this->scoreRow($score))->values()->all(),
        ])->values();

        return [
            'template' => $template ? [
                'isActive' => $template->is_active,
                'positionName' => $employee->position->name,
                'indicators' => $template->indicators->map(fn (KpiIndicator $indicator) => [
                    'name' => $indicator->name,
                    'source' => $indicator->source->value,
                    'metricLabel' => $indicator->metric_key ? $this->metrics->label($indicator->metric_key) : null,
                    'target' => (float) $indicator->target,
                    'weight' => (float) $indicator->weight,
                    'direction' => $indicator->direction->value,
                    'unit' => $this->metrics->unit($indicator->metric_key),
                ])->values()->all(),
            ] : null,
            'hasAccount' => $employee->user_id !== null,
            'periods' => $rows->all(),
            'trend' => $rows->reverse()->map(fn (array $row) => [
                'period' => $row['period'],
                'label' => $row['label'],
                'total' => $row['total'],
            ])->values()->all(),
        ];
    }

    /**
     * Figures for the SDM dashboard: the latest CLOSED month's averages per
     * division/position and its top/bottom 5, plus the OPEN month's progress.
     *
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        $latest = KpiPeriod::query()->closed()->orderByDesc('period')->first();
        $open = KpiPeriod::query()->where('status', KpiPeriodStatus::Open->value)->orderByDesc('period')->first();

        $board = $latest ? collect($this->periodBoard($latest)) : collect();

        $groupAverage = fn (string $key, string $name) => $board
            ->groupBy($key)
            ->map(fn (Collection $rows) => [
                'name' => $rows->first()[$name],
                'average' => $this->average($rows->pluck('total')),
                'employees' => $rows->count(),
            ])
            ->sortByDesc('average')
            ->values()
            ->all();

        $ranked = $board->filter(fn (array $row) => $row['total'] !== null)->sortByDesc('total')->values();
        $brief = fn (array $row) => collect($row)->only(['employeeId', 'name', 'positionName', 'divisionName', 'total'])->all();

        return [
            'latestClosed' => $latest ? [
                'id' => $latest->id,
                'period' => $latest->period,
                'label' => $this->monthLabel($latest->period),
                'average' => $this->average($board->pluck('total')),
                'employees' => $board->count(),
            ] : null,
            'byDivision' => $groupAverage('divisionId', 'divisionName'),
            'byPosition' => $groupAverage('positionId', 'positionName'),
            'top' => $ranked->take(5)->map($brief)->values()->all(),
            'bottom' => $ranked->reverse()->take(5)->map($brief)->values()->all(),
            'openPeriod' => $open ? $this->openProgress($open) : null,
        ];
    }

    /**
     * One row per employee scored in the month (optionally filtered per
     * division/position), with their indicator rows, ranked by total
     * within their position.
     *
     * @return list<array<string, mixed>>
     */
    public function periodBoard(KpiPeriod $period, ?int $divisionId = null, ?int $positionId = null): array
    {
        $scores = KpiScore::query()
            ->where('kpi_period_id', $period->id)
            ->whereIn('employee_id', Employee::query()->hrEligible()->inStructure($divisionId, $positionId)->select('id'))
            ->with(['employee:id,name,position_id,user_id,is_active', 'employee.position:id,name,division_id', 'employee.position.division:id,name'])
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $rows = $scores->map(function (Collection $rows) {
            /** @var Employee $employee */
            $employee = $rows->first()->employee;

            return [
                'employeeId' => $employee->id,
                'name' => $employee->name,
                'hasAccount' => $employee->user_id !== null,
                'positionId' => $employee->position?->id,
                'positionName' => $employee->position?->name ?? '—',
                'divisionId' => $employee->position?->division?->id,
                'divisionName' => $employee->position?->division?->name ?? '—',
                'total' => $this->total($rows),
                'manualMissing' => $rows->filter(fn (KpiScore $s) => $s->source === KpiIndicatorSource::Manual && $s->actual === null)->count(),
                'autoMissing' => $rows->filter(fn (KpiScore $s) => $s->source === KpiIndicatorSource::Auto && $s->actual === null)->count(),
                'rows' => $rows->map(fn (KpiScore $score) => $this->scoreRow($score))->values()->all(),
            ];
        });

        return $rows
            ->groupBy('positionId')
            ->flatMap(fn (Collection $group) => $group
                ->sortByDesc(fn (array $row) => $row['total'] ?? -1)
                ->values()
                ->map(fn (array $row, int $i) => [...$row, 'rank' => $i + 1, 'rankOf' => $group->count()]))
            ->sortBy([['divisionName', 'asc'], ['positionName', 'asc'], ['rank', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Company average month total per period (oldest first, last 12), for
     * the KPI page's trend chart.
     *
     * @return list<array{period: string, label: string, status: string, average: float|null, employees: int}>
     */
    public function companyTrend(): array
    {
        return KpiPeriod::query()
            ->orderByDesc('period')
            ->limit(self::HISTORY_MONTHS)
            ->get()
            ->reverse()
            ->map(function (KpiPeriod $period) {
                $totals = $this->periodTotals($period);

                return [
                    'period' => $period->period,
                    'label' => $this->monthLabel($period->period, short: true),
                    'status' => $period->status->value,
                    'average' => $this->average($totals),
                    'employees' => $totals->count(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{id: int, period: string, label: string, computed: bool, employees: int, manualMissing: int, autoMissing: int} */
    public function openProgress(KpiPeriod $period): array
    {
        $scores = KpiScore::query()->where('kpi_period_id', $period->id)->get(['id', 'employee_id', 'source', 'actual']);

        return [
            'id' => $period->id,
            'period' => $period->period,
            'label' => $this->monthLabel($period->period),
            'computed' => $scores->isNotEmpty(),
            'employees' => $scores->pluck('employee_id')->unique()->count(),
            'manualMissing' => $scores->filter(fn (KpiScore $s) => $s->source === KpiIndicatorSource::Manual && $s->actual === null)->count(),
            'autoMissing' => $scores->filter(fn (KpiScore $s) => $s->source === KpiIndicatorSource::Auto && $s->actual === null)->count(),
        ];
    }

    /**
     * Divisions → positions with their template, for the template page.
     * `withoutAccount` = active eligible employees of the position with no
     * linked account (they can't get AUTO values — the page warns).
     *
     * @return list<array<string, mixed>>
     */
    public function templateBoard(): array
    {
        $employees = Employee::query()->hrEligible()->active()
            ->with('user.roles:id,name')
            ->get(['id', 'position_id', 'user_id'])
            ->groupBy('position_id');

        return Division::query()
            ->ordered()
            ->with(['positions' => fn ($query) => $query->ordered()->with('kpiTemplate.indicators')])
            ->get()
            ->map(fn (Division $division) => [
                'id' => $division->id,
                'name' => $division->name,
                'isActive' => $division->is_active,
                'positions' => $division->positions->map(function (Position $position) use ($employees) {
                    $staff = $employees->get($position->id, collect());
                    $template = $position->kpiTemplate;

                    return [
                        'id' => $position->id,
                        'name' => $position->name,
                        'isActive' => $position->is_active,
                        'employees' => $staff->count(),
                        'withoutAccount' => $staff->whereNull('user_id')->count(),
                        'roles' => $staff->flatMap(fn (Employee $employee) => $employee->user?->roles->pluck('name') ?? [])->unique()->values()->all(),
                        'template' => $template ? [
                            'id' => $template->id,
                            'isActive' => $template->is_active,
                            'indicators' => $template->indicators->map(fn (KpiIndicator $indicator) => [
                                'id' => $indicator->id,
                                'name' => $indicator->name,
                                'source' => $indicator->source->value,
                                'metric_key' => $indicator->metric_key,
                                'target' => (float) $indicator->target,
                                'weight' => (float) $indicator->weight,
                                'direction' => $indicator->direction->value,
                            ])->values()->all(),
                        ] : null,
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function scoreRow(KpiScore $score): array
    {
        return [
            'id' => $score->id,
            'indicatorName' => $score->indicator_name,
            'source' => $score->source->value,
            'metricKey' => $score->metric_key,
            'metricLabel' => $score->metric_key ? $this->metrics->label($score->metric_key) : null,
            'unit' => $this->metrics->unit($score->metric_key),
            'direction' => $score->direction->value,
            'target' => (float) $score->target,
            'weight' => (float) $score->weight,
            'actual' => $score->actual === null ? null : (float) $score->actual,
            'score' => $score->score === null ? null : (float) $score->score,
            'weightedScore' => $score->weighted_score === null ? null : (float) $score->weighted_score,
        ];
    }

    /** Month totals (Σ weighted_score) of every employee scored in the period. */
    private function periodTotals(KpiPeriod $period): Collection
    {
        return KpiScore::query()
            ->where('kpi_period_id', $period->id)
            ->whereNotNull('weighted_score')
            ->whereIn('employee_id', Employee::query()->hrEligible()->select('id'))
            ->selectRaw('employee_id, SUM(weighted_score) as total')
            ->groupBy('employee_id')
            ->pluck('total')
            ->map(fn ($total) => round((float) $total, 2));
    }

    /** @param  Collection<int, KpiScore>  $rows */
    private function total(Collection $rows): ?float
    {
        $scored = $rows->filter(fn (KpiScore $row) => $row->weighted_score !== null);

        return $scored->isEmpty() ? null : round($scored->sum(fn (KpiScore $row) => (float) $row->weighted_score), 2);
    }

    private function average(Collection $values): ?float
    {
        $values = $values->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }

    private function parseMonth(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m', $value);
    }

    public function monthLabel(string $period, bool $short = false): string
    {
        return $this->parseMonth($period)?->translatedFormat($short ? 'M Y' : 'F Y') ?? $period;
    }

    /**
     * Contract used by PerformanceReviewService (§3.4): the employee's
     * average monthly KPI total over the semester's CLOSED periods only.
     * A month's total = sum of its `weighted_score` rows (0–120 scale).
     *
     * @return array{average: float|null, months: int}
     */
    public function semesterAverage(Employee $employee, int $year, int $semester): array
    {
        $first = $semester === 1 ? 1 : 7;
        $from = sprintf('%04d-%02d', $year, $first);
        $to = sprintf('%04d-%02d', $year, $first + 5);

        $totals = KpiScore::query()
            ->where('employee_id', $employee->id)
            ->whereHas('period', fn ($query) => $query
                ->where('status', KpiPeriodStatus::Closed->value)
                ->whereBetween('period', [$from, $to]))
            ->whereNotNull('weighted_score')
            ->selectRaw('kpi_period_id, SUM(weighted_score) as total')
            ->groupBy('kpi_period_id')
            ->pluck('total');

        return [
            'average' => $totals->isEmpty() ? null : round((float) $totals->avg(), 2),
            'months' => $totals->count(),
        ];
    }
}
