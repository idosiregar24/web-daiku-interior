<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * PRD §9.4 Audit Trail — "Semua aksi sensitif dicatat: approval
 * quotation, QA decision, perubahan finance, penjatuhan penalti. Log
 * menyimpan: user ID, timestamp, IP address, action type, data
 * before/after." Called from inside the acting service's transaction,
 * so an audit row exists exactly when the change it describes does.
 */
class AuditLogService
{
    /** Never written to the log, whatever a caller passes in. */
    private const REDACTED = ['password', 'remember_token'];

    /**
     * @param  string  $action  `{area}.{event}`, e.g. `quotation.ceo_approved` — the prefix is the Audit Log page's filter.
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  User|null  $actor  Defaults to the request's user; null (no request user) is recorded as "Sistem" — scheduled jobs.
     */
    public function record(string $action, Model $subject, ?array $old, ?array $new, ?User $actor = null): AuditLog
    {
        $actor ??= auth()->user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'model_type' => class_basename($subject),
            'model_id' => $subject->getKey(),
            'old_values' => $old === null ? null : $this->normalize(Arr::except($old, self::REDACTED)),
            'new_values' => $new === null ? null : $this->normalize(Arr::except($new, self::REDACTED)),
            // Null for scheduler/CLI runs (no REMOTE_ADDR).
            'ip_address' => request()->ip(),
        ]);
    }

    /** Enums → their value, dates → ISO strings, so the JSON reads the same as the UI. */
    private function normalize(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        }, $values);
    }
}
