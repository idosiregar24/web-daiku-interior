<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SDM (Sprint 10, decision #10) — the Divisi → Jabatan master. Changes are
 * audited because positions drive KPI templates and every SDM report.
 * A division/position in use is deactivated, never deleted; an unused one
 * may be deleted (a typo fixed right after creating it).
 */
class OrganizationStructureService
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function createDivision(array $data, User $actor): Division
    {
        return DB::transaction(function () use ($data, $actor) {
            $division = Division::create([...Arr::only($data, ['name', 'sort_order']), 'is_active' => true, 'created_by' => $actor->id]);
            $this->auditLogService->record('hr.division_created', $division, null, $division->only(['name', 'sort_order']), $actor);

            return $division;
        });
    }

    public function updateDivision(Division $division, array $data, User $actor): Division
    {
        return DB::transaction(function () use ($division, $data, $actor) {
            $division->fill(Arr::only($data, ['name', 'sort_order', 'is_active']));
            $changed = array_keys($division->getDirty());
            $old = Arr::only($division->getOriginal(), $changed);
            $division->save();

            if ($changed !== []) {
                $this->auditLogService->record('hr.division_updated', $division, $old, $division->only($changed), $actor);
            }

            return $division;
        });
    }

    public function deleteDivision(Division $division, User $actor): void
    {
        if ($division->positions()->exists()) {
            throw ValidationException::withMessages([
                'division' => "Divisi {$division->name} masih punya jabatan — nonaktifkan saja, jangan dihapus.",
            ]);
        }

        DB::transaction(function () use ($division, $actor) {
            $this->auditLogService->record('hr.division_deleted', $division, $division->only(['name']), null, $actor);
            $division->delete();
        });
    }

    public function createPosition(array $data, User $actor): Position
    {
        return DB::transaction(function () use ($data, $actor) {
            $position = Position::create([...Arr::only($data, ['division_id', 'name', 'sort_order']), 'is_active' => true, 'created_by' => $actor->id]);
            $this->auditLogService->record('hr.position_created', $position, null, $position->only(['division_id', 'name', 'sort_order']), $actor);

            return $position;
        });
    }

    public function updatePosition(Position $position, array $data, User $actor): Position
    {
        return DB::transaction(function () use ($position, $data, $actor) {
            $position->fill(Arr::only($data, ['division_id', 'name', 'sort_order', 'is_active']));
            $changed = array_keys($position->getDirty());
            $old = Arr::only($position->getOriginal(), $changed);
            $position->save();

            if ($changed !== []) {
                $this->auditLogService->record('hr.position_updated', $position, $old, $position->only($changed), $actor);
            }

            return $position;
        });
    }

    public function deletePosition(Position $position, User $actor): void
    {
        if ($position->employees()->exists() || $position->kpiTemplate()->exists()) {
            throw ValidationException::withMessages([
                'position' => "Jabatan {$position->name} sudah dipakai karyawan atau template KPI — nonaktifkan saja, jangan dihapus.",
            ]);
        }

        DB::transaction(function () use ($position, $actor) {
            $this->auditLogService->record('hr.position_deleted', $position, $position->only(['division_id', 'name']), null, $actor);
            $position->delete();
        });
    }
}
