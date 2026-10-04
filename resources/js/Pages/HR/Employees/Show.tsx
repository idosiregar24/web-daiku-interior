import { EmployeeDisciplineTab, type EmployeeDisciplineData } from '@/Components/modules/hr/EmployeeDisciplineTab';
import { EmployeeFormDialog } from '@/Components/modules/hr/EmployeeFormDialog';
import { EmployeeKpiTab, type EmployeeKpiData } from '@/Components/modules/hr/EmployeeKpiTab';
import { EmployeeReviewsTab, type EmployeeReviewsData } from '@/Components/modules/hr/EmployeeReviewsTab';
import { EmployeeSalaryTab, type EmployeeSalaryData } from '@/Components/modules/hr/EmployeeSalaryTab';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { Button } from '@/Components/ui/button';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { Division, Employee, User } from '@/types';
import { Head } from '@inertiajs/react';
import { BadgeDollarSign, ClipboardList, Gavel, IdCard, Pencil, Target, UserRound } from 'lucide-react';
import { useState } from 'react';

const TAB_LABEL: Record<string, string> = {
    summary: 'Ringkasan',
    discipline: 'Kedisiplinan',
    salary: 'Gaji',
    kpi: 'KPI',
    reviews: 'Evaluasi',
};

interface EmployeeShowProps {
    employee: Employee & { user?: Pick<User, 'id' | 'name' | 'email'> | null; creator?: Pick<User, 'id' | 'name'> };
    discipline: EmployeeDisciplineData;
    salary: EmployeeSalaryData;
    kpi: EmployeeKpiData;
    reviews: EmployeeReviewsData;
    canManage: boolean;
    canDecideSalary: boolean;
    structure: Division[];
    linkableUsers: Pick<User, 'id' | 'name'>[];
}

/**
 * SDM (Sprint 10) — employee profile: Ringkasan · Kedisiplinan · Gaji · KPI ·
 * Evaluasi. Each tab renders its part's component with the data its service
 * returns (`forEmployee()`); the same components back "Milik Saya".
 */
export default function EmployeeShow({
    employee,
    discipline,
    salary,
    kpi,
    reviews,
    canManage,
    canDecideSalary,
    structure,
    linkableUsers,
}: EmployeeShowProps) {
    const [tab, setTab] = useState(() => {
        const requested = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;

        return requested && requested in TAB_LABEL ? requested : 'summary';
    });
    const [editOpen, setEditOpen] = useState(false);

    return (
        <AppLayout breadcrumbs={[{ label: employee.name, href: route('hr.employees.show', { employee: employee.id }) }, { label: TAB_LABEL[tab] }]}>
            <Head title={employee.name} />

            <PageHeader
                title={employee.name}
                icon={IdCard}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        {employee.position?.name ?? '—'}
                        {employee.position?.division && <span className="text-daiku-muted">· {employee.position.division.name}</span>}
                        <StatusChip
                            status={employee.is_active ? 'ACTIVE_EMPLOYEE' : 'INACTIVE_EMPLOYEE'}
                            tone={employee.is_active ? 'success' : 'neutral'}
                            label={employee.is_active ? 'Aktif' : 'Nonaktif'}
                        />
                    </span>
                }
                actions={
                    canManage && (
                        <Button variant="outline" onClick={() => setEditOpen(true)}>
                            <Pencil className="size-4" />
                            Edit Data
                        </Button>
                    )
                }
            />

            <Tabs value={tab} onValueChange={setTab}>
                <UnderlineTabsList>
                    <TabsTrigger value="summary">
                        <UserRound />
                        Ringkasan
                    </TabsTrigger>
                    <TabsTrigger value="discipline">
                        <Gavel />
                        Kedisiplinan
                    </TabsTrigger>
                    <TabsTrigger value="salary">
                        <BadgeDollarSign />
                        Gaji
                    </TabsTrigger>
                    <TabsTrigger value="kpi">
                        <Target />
                        KPI
                    </TabsTrigger>
                    <TabsTrigger value="reviews">
                        <ClipboardList />
                        Evaluasi
                    </TabsTrigger>
                </UnderlineTabsList>

                <TabsContent value="summary" className="mt-6">
                    <SectionCard title="Data Karyawan" icon={UserRound}>
                        <DetailList>
                            <DetailItem label="Divisi">{employee.position?.division?.name ?? '—'}</DetailItem>
                            <DetailItem label="Jabatan">{employee.position?.name ?? '—'}</DetailItem>
                            <DetailItem label="Tanggal Bergabung">{formatDate(employee.join_date)}</DetailItem>
                            <DetailItem label="Gaji Pokok" valueClassName="tabular-nums">
                                {formatRupiah(employee.base_salary)}
                            </DetailItem>
                            <DetailItem label="Rekening">
                                {employee.account_no ? `${employee.bank_name ?? ''} ${employee.account_no}`.trim() : '—'}
                            </DetailItem>
                            <DetailItem label="Akun Sistem">
                                {employee.user ? `${employee.user.name} (${employee.user.email})` : 'Tidak ditautkan'}
                            </DetailItem>
                            <DetailItem label="Catatan" className="sm:col-span-2">
                                {employee.notes ?? '—'}
                            </DetailItem>
                        </DetailList>
                    </SectionCard>
                </TabsContent>

                <TabsContent value="discipline" className="mt-6">
                    <EmployeeDisciplineTab employee={employee} data={discipline} canManage={canManage} />
                </TabsContent>

                <TabsContent value="salary" className="mt-6">
                    <EmployeeSalaryTab employee={employee} data={salary} canManage={canManage} canDecide={canDecideSalary} />
                </TabsContent>

                <TabsContent value="kpi" className="mt-6">
                    <EmployeeKpiTab employee={employee} data={kpi} canManage={canManage} />
                </TabsContent>

                <TabsContent value="reviews" className="mt-6">
                    <EmployeeReviewsTab employee={employee} data={reviews} canManage={canManage} />
                </TabsContent>
            </Tabs>

            {canManage && (
                <EmployeeFormDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    employee={employee}
                    employees={[employee]}
                    linkableUsers={linkableUsers}
                    structure={structure}
                />
            )}
        </AppLayout>
    );
}
