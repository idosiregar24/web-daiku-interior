<?php

namespace App\Services;

use App\Enums\DailyActivityReviewStatus;
use App\Enums\NotificationType;
use App\Enums\ProjectStatus;
use App\Models\DailyActivity;
use App\Models\DailyActivityReview;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\DailyFormSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 29 Sub 13 — "Aktivitas Harian" & "Periksa Aktivitas" (decisions I1–I2).
 *
 * Every account except a Tukang writes what it did today (several entries a
 * day, each optionally on a project) and may change or remove an entry only
 * on its own day. HR checks a past day per person — Sesuai / Perlu
 * klarifikasi (note, the employee is notified) / Tidak mengisi — append-only,
 * the newest check counts. Only working days (DailyFormSchedule) are owed:
 * a holiday never lists "Tidak mengisi" and never enters HR's queue.
 * The KPI of Sub 14 reads the same two tables.
 */
class DailyActivityService
{
    /** Days of history on the employee's own page (today excluded). */
    public const HISTORY_DAYS = 14;

    public function __construct(
        private AuditLogService $auditLogService,
        private NotificationService $notificationService,
    ) {}

    public static function today(): Carbon
    {
        return DailyFormSchedule::now()->startOfDay();
    }

    /** "Periksa Aktivitas" opens on the previous working day ("kemarin"; Saturday on a Monday). */
    public static function defaultReviewDate(): Carbon
    {
        return DailyFormSchedule::previousWorkDay(self::today());
    }

    // ── Writing (own entries, today only) ───────────────────────────────

    /** @param  array{description: string, project_id?: int|null}  $data */
    public function create(User $user, array $data): DailyActivity
    {
        $projectId = $this->projectId($user, $data['project_id'] ?? null);

        return DailyActivity::create([
            'user_id' => $user->id,
            'date' => self::today()->toDateString(),
            'description' => trim($data['description']),
            'project_id' => $projectId,
        ]);
    }

    /** @param  array{description: string, project_id?: int|null}  $data */
    public function update(DailyActivity $activity, array $data, User $user): DailyActivity
    {
        $this->assertEditable($activity);

        $activity->update([
            'description' => trim($data['description']),
            'project_id' => $this->projectId($user, $data['project_id'] ?? null),
        ]);

        return $activity;
    }

    public function delete(DailyActivity $activity): void
    {
        $this->assertEditable($activity);

        $activity->delete();
    }

    /**
     * Running projects the writer can see (Project::visibleTo — an Asisten PM
     * only its own) — the optional "Proyek" of an entry.
     *
     * @return Collection<int, Project>
     */
    public function projectOptions(User $user): Collection
    {
        return Project::query()
            ->visibleTo($user)
            ->where('status', ProjectStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The employee's own page: today's entries and the last HISTORY_DAYS
     * days — every working day (an empty one shows "Tidak mengisi") plus any
     * holiday that has entries — each with HR's newest check.
     *
     * @return array{today: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function ownDays(User $user): array
    {
        $today = self::today();
        $from = $today->copy()->subDays(self::HISTORY_DAYS);

        $activities = DailyActivity::query()
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->with('project:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (DailyActivity $activity) => $activity->date->toDateString());

        $reviews = $this->latestReviews(
            DailyActivityReview::query()->where('user_id', $user->id)->whereDate('date', '>=', $from->toDateString()),
            fn (DailyActivityReview $review) => $review->date->toDateString(),
        );

        $history = [];

        for ($day = $today->copy()->subDay(); $day->gte($from); $day->subDay()) {
            $key = $day->toDateString();
            $entries = $activities->get($key, collect());
            $isWorkDay = DailyFormSchedule::isWorkDay($day);

            if (! $isWorkDay && $entries->isEmpty()) {
                continue;
            }

            $history[] = [
                'date' => $key,
                'is_work_day' => $isWorkDay,
                'activities' => $entries->map(fn (DailyActivity $activity) => $this->presentActivity($activity))->values()->all(),
                'review' => $this->presentReview($reviews->get($key)),
            ];
        }

        return [
            'today' => $activities->get($today->toDateString(), collect())
                ->map(fn (DailyActivity $activity) => $this->presentActivity($activity))
                ->values()
                ->all(),
            'history' => $history,
        ];
    }

    // ── Checking (HR) ───────────────────────────────────────────────────

    /**
     * Employees still unchecked on `$date` — the Perlu Tindakan queue and the
     * page's "Belum diperiksa" count. On a holiday only those who wrote
     * something are owed a check.
     */
    public function unreviewedQuery(string $date): Builder
    {
        return $this->sheetQuery($date)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('daily_activity_reviews')
                ->whereColumn('daily_activity_reviews.user_id', 'employees.user_id')
                ->whereDate('daily_activity_reviews.date', $date));
    }

    /**
     * One row per employee checked on `$date`: their entries (or none) and
     * HR's newest check.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array{total: int, filled: int, empty: int, unreviewed: int}}
     */
    public function sheet(string $date): array
    {
        $employees = $this->sheetQuery($date)
            ->with(['position:id,name,division_id', 'position.division:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'user_id', 'position_id']);

        $userIds = $employees->pluck('user_id')->all();

        $activities = DailyActivity::query()
            ->onDate($date)
            ->whereIn('user_id', $userIds)
            ->with('project:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $reviews = $this->latestReviews(
            DailyActivityReview::query()->onDate($date)->whereIn('user_id', $userIds),
            fn (DailyActivityReview $review) => $review->user_id,
        );

        $rows = $employees->map(function (Employee $employee) use ($activities, $reviews) {
            $entries = $activities->get($employee->user_id, collect());

            return [
                'id' => $employee->id,
                'user_id' => $employee->user_id,
                'name' => $employee->name,
                'position' => $employee->position?->name,
                'division' => $employee->position?->division?->name,
                'activities' => $entries->map(fn (DailyActivity $activity) => $this->presentActivity($activity))->values()->all(),
                'review' => $this->presentReview($reviews->get($employee->user_id)),
            ];
        })->values();

        $filled = $rows->filter(fn (array $row) => $row['activities'] !== [])->count();

        return [
            'rows' => $rows->all(),
            'summary' => [
                'total' => $rows->count(),
                'filled' => $filled,
                'empty' => $rows->count() - $filled,
                'unreviewed' => $rows->whereNull('review')->count(),
            ],
        ];
    }

    /**
     * HR's check of one employee's day. Sesuai needs entries, Tidak mengisi
     * an empty working day; Perlu klarifikasi needs a note and notifies the
     * employee. A new row every time — the newest counts.
     *
     * @param  array{user_id: int, date: string, status: string, note?: string|null}  $data
     */
    public function review(array $data, User $actor): DailyActivityReview
    {
        $date = $this->assertReviewableDate($data['date']);
        $status = DailyActivityReviewStatus::from($data['status']);
        $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;

        $employee = Employee::query()->activityReviewable($date)->where('user_id', $data['user_id'])->with('user')->first();

        if (! $employee) {
            throw ValidationException::withMessages(['user_id' => 'Karyawan ini tidak termasuk pemeriksaan aktivitas harian.']);
        }

        $filled = DailyActivity::query()->where('user_id', $employee->user_id)->onDate($date)->exists();
        $isWorkDay = DailyFormSchedule::isWorkDay(Carbon::parse($date));

        if ($status === DailyActivityReviewStatus::Sesuai && ! $filled) {
            throw ValidationException::withMessages(['status' => "{$employee->name} tidak mengisi aktivitas — catat \"Tidak mengisi\" atau minta klarifikasi."]);
        }

        if ($status === DailyActivityReviewStatus::TidakMengisi && $filled) {
            throw ValidationException::withMessages(['status' => "{$employee->name} sudah mengisi aktivitas pada hari itu."]);
        }

        if (! $filled && ! $isWorkDay) {
            throw ValidationException::withMessages(['status' => 'Hari libur — yang tidak mengisi tidak perlu diperiksa.']);
        }

        if ($status === DailyActivityReviewStatus::PerluKlarifikasi && $note === null) {
            throw ValidationException::withMessages(['note' => 'Catatan klarifikasi wajib diisi.']);
        }

        return DB::transaction(function () use ($employee, $date, $status, $note, $actor) {
            $review = $this->record($employee, $date, $status, $note, $actor);

            $user = $employee->user;

            if ($status === DailyActivityReviewStatus::PerluKlarifikasi && $user?->is_active) {
                $this->notificationService->notify(
                    $user,
                    NotificationType::DailyActivityClarification,
                    'Aktivitas harian perlu klarifikasi',
                    'SDM meminta klarifikasi aktivitas Anda tanggal '.Carbon::parse($date)->translatedFormat('d F Y').": {$note}",
                    ['date' => $date],
                );
            }

            return $review;
        });
    }

    /**
     * "Tandai semua yang terisi Sesuai": every unchecked employee of `$date`
     * — Sesuai for those who wrote something, Tidak mengisi for the empty
     * ones (a working day only). People already checked keep their check.
     *
     * @return array{sesuai: int, tidak_mengisi: int}
     */
    public function reviewAllFilled(string $date, User $actor): array
    {
        $date = $this->assertReviewableDate($date);

        return DB::transaction(function () use ($date, $actor) {
            $employees = $this->unreviewedQuery($date)->orderBy('name')->get(['id', 'name', 'user_id']);

            $filledIds = DailyActivity::query()
                ->onDate($date)
                ->whereIn('user_id', $employees->pluck('user_id'))
                ->distinct()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $counts = ['sesuai' => 0, 'tidak_mengisi' => 0];

            foreach ($employees as $employee) {
                $filled = in_array((int) $employee->user_id, $filledIds, true);
                $status = $filled ? DailyActivityReviewStatus::Sesuai : DailyActivityReviewStatus::TidakMengisi;

                $this->record($employee, $date, $status, null, $actor);
                $counts[$filled ? 'sesuai' : 'tidak_mengisi']++;
            }

            return $counts;
        });
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** Everyone listed on `$date`: reviewable employees; on a holiday only those with entries. */
    private function sheetQuery(string $date): Builder
    {
        return Employee::query()
            ->activityReviewable($date)
            ->when(! DailyFormSchedule::isWorkDay(Carbon::parse($date)), fn (Builder $query) => $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('daily_activities')
                ->whereColumn('daily_activities.user_id', 'employees.user_id')
                ->whereDate('daily_activities.date', $date)));
    }

    private function record(Employee $employee, string $date, DailyActivityReviewStatus $status, ?string $note, User $actor): DailyActivityReview
    {
        $review = DailyActivityReview::create([
            'user_id' => $employee->user_id,
            'date' => $date,
            'status' => $status,
            'note' => $note,
            'reviewed_by' => $actor->id,
        ]);

        $this->auditLogService->record('hr.activity_reviewed', $review, null, [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'user_id' => $employee->user_id,
            'date' => $date,
            'status' => $status->value,
            'note' => $note,
        ], $actor);

        return $review;
    }

    /** A past day only (WIB) — today's entries can still change. Returns Y-m-d. */
    private function assertReviewableDate(string $date): string
    {
        $day = Carbon::parse($date, DailyFormSchedule::TIMEZONE)->startOfDay();

        if (! $day->lt(self::today())) {
            throw ValidationException::withMessages(['date' => 'Aktivitas baru bisa diperiksa setelah harinya lewat.']);
        }

        return $day->toDateString();
    }

    private function assertEditable(DailyActivity $activity): void
    {
        if (! $activity->isEditableToday()) {
            throw ValidationException::withMessages(['description' => 'Aktivitas hanya bisa diubah atau dihapus pada hari yang sama.']);
        }
    }

    /** The project an entry may point at — one of projectOptions(), or none. */
    private function projectId(User $user, mixed $projectId): ?int
    {
        if (blank($projectId)) {
            return null;
        }

        $visible = Project::query()
            ->visibleTo($user)
            ->where('status', ProjectStatus::Active->value)
            ->whereKey((int) $projectId)
            ->exists();

        if (! $visible) {
            throw ValidationException::withMessages(['project_id' => 'Proyek tidak ditemukan atau sudah tidak berjalan.']);
        }

        return (int) $projectId;
    }

    /**
     * Newest review per key (rows ordered by id — append-only, the last wins).
     *
     * @param  callable(DailyActivityReview): (int|string)  $key
     * @return Collection<int|string, DailyActivityReview>
     */
    private function latestReviews(Builder $query, callable $key): Collection
    {
        return $query
            ->with('reviewer:id,name')
            ->orderBy('id')
            ->get()
            ->keyBy($key);
    }

    /** @return array<string, mixed> */
    private function presentActivity(DailyActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'date' => $activity->date->toDateString(),
            'description' => $activity->description,
            'project' => $activity->project ? ['id' => $activity->project->id, 'name' => $activity->project->name] : null,
            'created_at' => $activity->created_at?->toIso8601String(),
            'updated_at' => $activity->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentReview(?DailyActivityReview $review): ?array
    {
        if (! $review) {
            return null;
        }

        return [
            'status' => $review->status->value,
            'note' => $review->note,
            'reviewer' => $review->reviewer?->name,
            'reviewed_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
