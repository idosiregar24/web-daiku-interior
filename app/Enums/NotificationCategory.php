<?php

namespace App\Enums;

/**
 * Sprint 18 — the groups a user can mute on their device (Pengaturan
 * Notifikasi, Sub 05). Values must match the `NotificationCategory` union
 * in resources/js/types/index.d.ts.
 */
enum NotificationCategory: string
{
    case Crm = 'CRM';
    case Design = 'DESIGN';
    case Quotation = 'QUOTATION';
    case Finance = 'FINANCE';
    case Project = 'PROJECT';
    case Task = 'TASK';
    case Overtime = 'OVERTIME';
    case Qa = 'QA';
    case Logistics = 'LOGISTICS';
    case Hr = 'HR';

    /**
     * What a person in these roles can receive — the toggles shown in
     * Pengaturan Notifikasi (it would only confuse a Tukang to see
     * "RAB & Penawaran"). Mirrors who the triggers address; categories the
     * user actually received are added on top by the page, so a missed
     * mapping never hides a real one.
     *
     * @param  iterable<string>  $roles
     * @return list<self>
     */
    public static function forRoles(iterable $roles): array
    {
        $map = [
            'CEO' => self::cases(),
            'SUPERADMIN' => self::cases(),
            'MARKETING' => [self::Crm, self::Design, self::Quotation, self::Finance, self::Project, self::Hr],
            'ESTIMATOR' => [self::Design, self::Quotation, self::Logistics, self::Hr],
            'PM' => [self::Design, self::Quotation, self::Project, self::Task, self::Overtime, self::Qa, self::Logistics, self::Hr],
            'ASISTEN_PM' => [self::Quotation, self::Project, self::Task, self::Qa, self::Logistics, self::Hr],
            'DESIGNER' => [self::Design, self::Hr],
            'KEPALA_DESAIN' => [self::Design, self::Hr],
            'QA' => [self::Qa, self::Hr],
            'FINANCE' => [self::Finance, self::Project, self::Task, self::Overtime, self::Hr],
            'LOGISTICS' => [self::Logistics, self::Project, self::Hr],
            'HR' => [self::Hr],
            'FIELD_STAFF' => [self::Task, self::Overtime, self::Logistics],
        ];

        $categories = [];

        foreach ($roles as $role) {
            foreach ($map[$role] ?? [] as $category) {
                $categories[$category->value] = $category;
            }
        }

        return array_values(array_filter(self::cases(), fn (self $case) => isset($categories[$case->value])));
    }

    /** Does this category carry any "klien menunggu" (P1) type? Those are never fully muted. */
    public function hasClientWaiting(): bool
    {
        foreach (NotificationType::cases() as $type) {
            if ($type->category() === $this && $type->priority() === NotificationPriority::ClientWaiting) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return match ($this) {
            self::Crm => 'Lead & Klien',
            self::Design => 'Desain',
            self::Quotation => 'RAB & Penawaran',
            self::Finance => 'Invoice & Termin',
            self::Project => 'Proyek',
            self::Task => 'Tugas & Form Harian',
            self::Overtime => 'Lembur',
            self::Qa => 'QA',
            self::Logistics => 'Material & Logistik',
            self::Hr => 'SDM',
        };
    }
}
