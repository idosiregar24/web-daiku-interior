import { KpiTemplateDialog } from '@/Components/modules/hr/KpiTemplateDialog';
import {
    formatKpiValue,
    KPI_SOURCE_LABEL,
    type KpiMetricOption,
    type KpiTemplateDivision,
    type KpiTemplatePosition,
} from '@/Components/modules/hr/KpiTypes';
import { EmptyState } from '@/Components/shared/EmptyState';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, Eye, Pencil, Power } from 'lucide-react';
import { useState } from 'react';

interface KpiTemplatesProps {
    divisions: KpiTemplateDivision[];
    metrics: KpiMetricOption[];
    canManage: boolean;
}

function templateChip(position: KpiTemplatePosition) {
    if (!position.template) return <StatusChip status="KPI_TEMPLATE_NONE" tone="neutral" label="Belum ada" />;
    return position.template.isActive ? (
        <StatusChip status="KPI_TEMPLATE_ACTIVE" tone="success" label="Aktif" />
    ) : (
        <StatusChip status="KPI_TEMPLATE_INACTIVE" tone="neutral" label="Nonaktif" />
    );
}

/**
 * SDM (Sprint 10, §3.3, decisions #9/#10) — one KPI template per
 * structured position, grouped by division. HR edits; the CEO reads.
 */
export default function KpiTemplates({ divisions, metrics, canManage }: KpiTemplatesProps) {
    const [editing, setEditing] = useState<{ position: KpiTemplatePosition; divisionName: string } | null>(null);
    const [viewing, setViewing] = useState<number | null>(null);
    const metricLabel = Object.fromEntries(metrics.map((metric) => [metric.key, metric]));

    const warnings = divisions
        .flatMap((division) => division.positions)
        .filter((position) => position.template?.isActive && position.withoutAccount > 0 && position.template.indicators.some((row) => row.source === 'AUTO'));

    return (
        <AppLayout>
            <Head title="Template KPI" />

            <PageHeader
                title="Template KPI"
                icon={ClipboardList}
                description="Indikator KPI per jabatan. Total bobot tiap template wajib 100%. Perubahan template hanya berlaku untuk periode yang masih terbuka — periode yang sudah ditutup tidak berubah."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('hr.kpi.index')}>Kembali ke KPI</Link>
                    </Button>
                }
            />

            <ModuleTabs />

            <div className="space-y-6">
                {warnings.length > 0 && (
                    <Notice tone="warning">
                        Jabatan {warnings.map((position) => position.name).join(', ')} memakai indikator otomatis, tetapi ada karyawannya yang tidak punya
                        akun sistem. Indikator otomatis mereka tidak bisa dihitung — tautkan akun di profil karyawan, atau andalkan indikator manual.
                    </Notice>
                )}

                {divisions.length === 0 && (
                    <SectionCard title="Belum ada jabatan">
                        <EmptyState title="Belum ada divisi & jabatan." description="Tambahkan dulu di SDM → Divisi & Jabatan." icon={ClipboardList} />
                    </SectionCard>
                )}

                {divisions.map((division) => (
                    <SectionCard
                        key={division.id}
                        title={division.name}
                        description={division.isActive ? `${division.positions.length} jabatan` : 'Divisi nonaktif'}
                        flush
                    >
                        {division.positions.length === 0 ? (
                            <EmptyState title="Belum ada jabatan di divisi ini." />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[640px] text-sm">
                                    <thead className={TABLE_HEAD_CLASS}>
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">Jabatan</th>
                                            <th className="px-4 py-2 text-left font-medium">Karyawan</th>
                                            <th className="px-4 py-2 text-left font-medium">Indikator</th>
                                            <th className="px-4 py-2 text-left font-medium">Template</th>
                                            <th className="px-4 py-2" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {division.positions.map((position) => {
                                            const indicators = position.template?.indicators ?? [];
                                            const open = viewing === position.id;

                                            return (
                                                <tr key={position.id} className="align-top">
                                                    <td className="px-4 py-3">
                                                        <p className="font-medium text-foreground">{position.name}</p>
                                                        {!position.isActive && <p className="text-xs text-daiku-muted">Jabatan nonaktif</p>}
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {position.employees}
                                                        {position.withoutAccount > 0 && (
                                                            <p className="text-xs text-daiku-muted">{position.withoutAccount} tanpa akun</p>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {indicators.length === 0 ? (
                                                            <span className="text-daiku-muted">—</span>
                                                        ) : (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    className="text-left underline-offset-4 hover:underline decoration-daiku-yellow"
                                                                    onClick={() => setViewing(open ? null : position.id)}
                                                                    aria-expanded={open}
                                                                >
                                                                    {indicators.length} indikator ·{' '}
                                                                    {indicators.filter((row) => row.source === 'AUTO').length} otomatis
                                                                </button>
                                                                {open && (
                                                                    <ul className="mt-2 space-y-1 text-xs text-daiku-muted">
                                                                        {indicators.map((row) => (
                                                                            <li key={row.id}>
                                                                                <span className="font-medium text-foreground">{row.name}</span> ·{' '}
                                                                                {KPI_SOURCE_LABEL[row.source]}
                                                                                {row.metric_key && ` (${metricLabel[row.metric_key]?.label ?? row.metric_key})`} ·
                                                                                target {formatKpiValue(row.target, row.metric_key ? metricLabel[row.metric_key]?.unit : null)} ·
                                                                                bobot {formatKpiValue(row.weight, '%')}
                                                                            </li>
                                                                        ))}
                                                                    </ul>
                                                                )}
                                                            </>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">{templateChip(position)}</td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex justify-end gap-1">
                                                            {canManage ? (
                                                                <>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        onClick={() => setEditing({ position, divisionName: division.name })}
                                                                    >
                                                                        <Pencil className="size-4" />
                                                                        {position.template ? 'Ubah' : 'Buat'}
                                                                    </Button>
                                                                    {position.template && (
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                router.patch(
                                                                                    route('hr.kpi.templates.toggle', { position: position.id }),
                                                                                    {},
                                                                                    { preserveScroll: true },
                                                                                )
                                                                            }
                                                                        >
                                                                            <Power className="size-4" />
                                                                            {position.template.isActive ? 'Nonaktifkan' : 'Aktifkan'}
                                                                        </Button>
                                                                    )}
                                                                </>
                                                            ) : (
                                                                indicators.length > 0 && (
                                                                    <Button variant="ghost" size="sm" onClick={() => setViewing(open ? null : position.id)}>
                                                                        <Eye className="size-4" />
                                                                        {open ? 'Sembunyikan' : 'Lihat'}
                                                                    </Button>
                                                                )
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </SectionCard>
                ))}
            </div>

            {canManage && (
                <KpiTemplateDialog
                    open={editing !== null}
                    onOpenChange={(open) => !open && setEditing(null)}
                    position={editing?.position ?? null}
                    divisionName={editing?.divisionName ?? ''}
                    metrics={metrics}
                />
            )}
        </AppLayout>
    );
}
