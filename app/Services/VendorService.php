<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 11 Sub 2 — Master Vendor. CEO + SUPERADMIN only (route
 * `role:CEO`); other modules (supplier debts, project material purchases)
 * only reference an active vendor by id.
 */
class VendorService
{
    public function create(array $data, User $actor): Vendor
    {
        return Vendor::create([
            ...$this->normalize($data),
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $actor->id,
        ]);
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->update([
            ...$this->normalize($data),
            'is_active' => $data['is_active'] ?? $vendor->is_active,
        ]);

        return $vendor;
    }

    /**
     * A vendor referenced by a supplier debt or a project purchase is part
     * of that history (restrictOnDelete FKs) — refuse with a readable
     * message and point at deactivation instead.
     */
    public function delete(Vendor $vendor): void
    {
        if ($vendor->isInUse()) {
            throw ValidationException::withMessages([
                'name' => "Vendor {$vendor->name} sudah dipakai — nonaktifkan saja, jangan dihapus.",
            ]);
        }

        $vendor->delete();
    }

    /** Trimmed name with collapsed spaces, so "Kaca  Jaya " can't sneak past the unique check. */
    private function normalize(array $data): array
    {
        $fields = ['name', 'contact', 'address', 'type', 'bank_name', 'bank_account_number', 'account_holder'];

        return collect($data)
            ->only($fields)
            ->map(fn ($value) => is_string($value) ? (trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '') ?: null) : $value)
            ->all();
    }
}
