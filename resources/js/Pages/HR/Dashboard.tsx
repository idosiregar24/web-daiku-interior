import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { DisciplinaryType, ReviewGrade, ReviewStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { BadgeDollarSign, ClipboardList, Gavel, IdCard, LayoutDashboard, Target, Users } from 'lucide-react';

interface Brief {
    id: number;
    name: string;
}

interface HrDashboardProps {
    headcount: { active: number; inactive: number; withoutAccount: number; joinedThisYear: number };
    discipline: {
        active_sp_count: number;
        employees_with_active_sp: number;
        issued_this_month: number;
        latest_active_sp: { id: number; employee: Brief & { position: string | null }; type: DisciplinaryType; issued_on: string; valid_until: string }[];
    };
    salary: {
        pending_count: number;
        pending: { id: number; employee: Brief | null; old_salary: string; new_salary: string; effective_date: string }[];
        scheduled_count: number;
        paid_last_month: number;
        last_month_label: string;
        base_salary_total: number;
    };
    kpi: {
        latestClosed: { id: number; label: string; average: number | null; employees: number } | null;
        byDivision: { name: string; average: number | null; employees: number }[];
        top: { employeeId: number; name: string; positionName: string | null; total: number | null }[];
        bottom: { employeeId: number; name: string; positionName: string | null; total: number | null }[];
        openPeriod: { label: string; computed: boolean; employees: number; manualMissing: number; autoMissing: number } | null;
    };
    reviews: {
        current: { label: string; counts: Record<ReviewStatus, number>; total: number; eligible: number };
        previous: { label: string; counts: Record<ReviewStatus, number>; total: number; eligible: number };
        awaiting_count: number;
        awaiting: { id: number; employee: Brief | null; period_label: string; final_score: number | null; grade: ReviewGrade | null }[];
        grade_distribution: Record<ReviewGrade, number>;
    };
}

const score = (value: number | null) => (value === null ? '—' : value.toLocaleString('id-ID', { maximumFractionDigits: 1 }));

function employeeLink(employee: Brief | null, tab?: string) {
    if (!employee) return '—';

    return (
        <Link
            href={route('hr.employees.show', { employee: employee.id, ...(tab ? { tab } : {}) })}
            className="font-medium text-daiku-dark underline-offset-4 hover:underline decoration-daiku-yellow"
        >
            {employee.name}
        </Link>
    );
}

/**
 * SDM dashboard (Sprint 10 SDM-6) — HR's landing page, read by the CEO too.
 * Each panel is its part's `dashboardSummary()`; links lead to the module page.
 */
export default function HrDashboard({ headcount, discipline, salary, kpi, reviews }: HrDashboardProps) {
    const reviewDone = reviews.previous.counts.APPROVED + reviews.previous.counts.ACKNOWLEDGED;

    return (
        <AppLayout>
            <Head title="Dashboard SDM" />

            <PageHeader
                title="Dashboard SDM"
                icon={LayoutDashboard}
                description="Ringkasan karyawan tetap: kedisiplinan, perubahan gaji, KPI bulanan, dan evaluasi semester."
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Karyawan Aktif"
                    value={headcount.active}
                    icon={Users}
                    hint={`${headcount.inactive} nonaktif · ${headcount.joinedThisYear} bergabung tahun ini`}
                />
                <StatCard
                    label="SP Aktif"
                    value={discipline.active_sp_count}
                    icon={Gavel}
                    tone={discipline.active_sp_count > 0 ? 'warning' : 'default'}
                    hint={`${discipline.employees_with_active_sp} karyawan · ${discipline.issued_this_month} catatan bulan ini`}
                />
                <StatCard
                    label="Pengajuan Gaji Menunggu CEO"
                    value={salary.pending_count}
                    icon={BadgeDollarSign}
                    tone={salary.pending_count > 0 ? 'warning' : 'default'}
                    hint={`${salary.scheduled_count} disetujui, menunggu tanggal berlaku`}
                />
                <StatCard
                    label="Evaluasi Menunggu CEO"
                    value={reviews.awaiting_count}
                    icon={ClipboardList}
                    tone={reviews.awaiting_count > 0 ? 'info' : 'default'}
                    hint={`${reviews.current.label}: ${reviews.current.total} dari ${reviews.current.eligible} dibuat`}
                />
            </div>

            {headcount.withoutAccount > 0 && (
                <Notice tone="info" className="mb-6">
                    {headcount.withoutAccount} karyawan aktif belum punya akun sistem — indikator KPI otomatis mereka tidak bisa dihitung dan
                    mereka tidak bisa membuka menu Kinerja Saya.
                </Notice>
            )}

            <div className="grid gap-6 xl:grid-cols-2">
                <SectionCard
                    title="KPI Bulanan"
                    description={kpi.latestClosed ? `Periode tertutup terakhir: ${kpi.latestClosed.label}` : 'Belum ada periode yang ditutup.'}
                    icon={Target}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('hr.kpi.index')}>Buka KPI</Link>
                        </Button>
                    }
                >
                    {kpi.openPeriod && (
                        <Notice tone={kpi.openPeriod.manualMissing > 0 || !kpi.openPeriod.computed ? 'warning' : 'info'} className="mb-4">
                            Periode {kpi.openPeriod.label} masih terbuka —{' '}
                            {kpi.openPeriod.computed
                                ? `${kpi.openPeriod.employees} karyawan dinilai, ${kpi.openPeriod.manualMissing} nilai manual belum diisi.`
                                : 'belum dihitung.'}
                        </Notice>
                    )}
                    {kpi.latestClosed ? (
                        <div className="space-y-5">
                            <div>
                                <p className="mb-2 text-xs font-medium text-muted-foreground">Rata-rata per divisi</p>
                                <div className="space-y-2">
                                    {kpi.byDivision.map((division) => (
                                        <div key={division.name} className="grid grid-cols-[8rem_1fr_3rem] items-center gap-3 text-sm">
                                            <span className="truncate">{division.name}</span>
                                            {/* KPI totals run 0–120; the bar shows them as a share of 120. */}
                                            <ProgressBar label={`KPI ${division.name}`} value={((division.average ?? 0) / 120) * 100} />
                                            <span className="text-right tabular-nums">{score(division.average)}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {[
                                    { title: 'Tertinggi', rows: kpi.top },
                                    { title: 'Terendah', rows: kpi.bottom },
                                ].map((list) => (
                                    <div key={list.title}>
                                        <p className="mb-2 text-xs font-medium text-muted-foreground">{list.title}</p>
                                        <ul className="space-y-1.5 text-sm">
                                            {list.rows.map((row) => (
                                                <li key={row.employeeId} className="flex items-center justify-between gap-2">
                                                    <span className="min-w-0 truncate">
                                                        {employeeLink({ id: row.employeeId, name: row.name }, 'kpi')}
                                                        <span className="ml-1 text-xs text-daiku-muted">{row.positionName}</span>
                                                    </span>
                                                    <span className="tabular-nums">{score(row.total)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <EmptyState title="Belum ada KPI yang ditutup." description="Hitung lalu tutup periode di halaman KPI." />
                    )}
                </SectionCard>

                <SectionCard
                    title="Evaluasi Semester"
                    description={`${reviews.previous.label}: ${reviewDone} dari ${reviews.previous.eligible} disetujui`}
                    icon={ClipboardList}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('hr.reviews.index')}>Buka Evaluasi</Link>
                        </Button>
                    }
                >
                    <div className="mb-5 grid grid-cols-5 gap-2 text-center">
                        {(Object.keys(reviews.grade_distribution) as ReviewGrade[]).map((grade) => (
                            <div key={grade} className="rounded-lg bg-daiku-gray px-2 py-3">
                                <p className="text-lg font-semibold tabular-nums">{reviews.grade_distribution[grade]}</p>
                                <p className="text-xs text-daiku-muted">Grade {grade}</p>
                            </div>
                        ))}
                    </div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground">Menunggu persetujuan CEO</p>
                    {reviews.awaiting.length === 0 ? (
                        <EmptyState title="Tidak ada evaluasi yang menunggu." />
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {reviews.awaiting.map((review) => (
                                <li key={review.id} className="flex items-center justify-between gap-2 py-2">
                                    <Link
                                        href={route('hr.reviews.show', { performance_review: review.id })}
                                        className="font-medium underline-offset-4 hover:underline decoration-daiku-yellow"
                                    >
                                        {review.employee?.name ?? '—'}
                                    </Link>
                                    <span className="text-xs text-daiku-muted">{review.period_label}</span>
                                    <span className="tabular-nums">
                                        {score(review.final_score)} {review.grade && `· ${review.grade}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title="Kedisiplinan"
                    description="SP yang masih berlaku"
                    icon={Gavel}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('hr.discipline.index')}>Buka Kedisiplinan</Link>
                        </Button>
                    }
                >
                    {discipline.latest_active_sp.length === 0 ? (
                        <EmptyState title="Tidak ada SP yang berlaku." />
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {discipline.latest_active_sp.map((record) => (
                                <li key={record.id} className="flex items-center justify-between gap-2 py-2">
                                    <span className="min-w-0 truncate">
                                        {employeeLink(record.employee, 'discipline')}
                                        <span className="ml-1 text-xs text-daiku-muted">{record.employee.position}</span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <StatusChip status={record.type} />
                                        <span className="text-xs text-daiku-muted">s.d. {formatDate(record.valid_until)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title="Gaji"
                    description={`Dibayar ${salary.last_month_label}: ${formatRupiah(salary.paid_last_month)} · total gaji pokok aktif ${formatRupiah(salary.base_salary_total)}`}
                    icon={BadgeDollarSign}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('hr.salary.index')}>Buka Gaji</Link>
                        </Button>
                    }
                >
                    {salary.pending.length === 0 ? (
                        <EmptyState title="Tidak ada pengajuan perubahan gaji yang menunggu." icon={IdCard} />
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {salary.pending.map((change) => (
                                <li key={change.id} className="flex items-center justify-between gap-2 py-2">
                                    {employeeLink(change.employee, 'salary')}
                                    <span className="tabular-nums">
                                        {formatRupiah(change.old_salary)} → {formatRupiah(change.new_salary)}
                                    </span>
                                    <span className="text-xs text-daiku-muted">berlaku {formatDate(change.effective_date)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
