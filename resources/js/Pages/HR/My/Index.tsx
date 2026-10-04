import { EmployeeDisciplineTab, type EmployeeDisciplineData } from '@/Components/modules/hr/EmployeeDisciplineTab';
import { EmployeeKpiTab, type EmployeeKpiData } from '@/Components/modules/hr/EmployeeKpiTab';
import { EmployeeReviewsTab, type EmployeeReviewsData } from '@/Components/modules/hr/EmployeeReviewsTab';
import { EmployeeSalaryTab, type EmployeeSalaryData } from '@/Components/modules/hr/EmployeeSalaryTab';
import { PageHeader } from '@/Components/shared/PageHeader';
import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import type { Employee } from '@/types';
import { Head } from '@inertiajs/react';
import { BadgeDollarSign, ClipboardList, Gavel, Target, UserRound } from 'lucide-react';
import { useState } from 'react';

const TAB_LABEL: Record<string, string> = {
    kpi: 'KPI',
    reviews: 'Evaluasi',
    discipline: 'Kedisiplinan',
    salary: 'Gaji',
};

interface MyHrIndexProps {
    employee: Pick<Employee, 'id' | 'name' | 'is_active' | 'join_date' | 'position'>;
    discipline: EmployeeDisciplineData;
    salary: EmployeeSalaryData;
    kpi: EmployeeKpiData;
    reviews: EmployeeReviewsData;
}

/**
 * SDM "Milik Saya" (Sprint 10, decision #6) — the signed-in employee's own
 * KPI (closed months), reviews (approved ones), warnings and salary
 * history. Read-only apart from acknowledging a review; every tab is the
 * same component HR sees on the profile, in `selfView` mode.
 */
export default function MyHrIndex({ employee, discipline, salary, kpi, reviews }: MyHrIndexProps) {
    const [tab, setTab] = useState(() => {
        const requested = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;

        return requested && requested in TAB_LABEL ? requested : 'kpi';
    });

    return (
        <AppLayout breadcrumbs={[{ label: TAB_LABEL[tab] }]}>
            <Head title="Kinerja Saya" />

            <PageHeader
                title="Kinerja Saya"
                icon={UserRound}
                description={`${employee.name} · ${employee.position?.name ?? '—'}${employee.position?.division ? ` · ${employee.position.division.name}` : ''} — KPI bulanan, evaluasi semester, catatan kedisiplinan, dan riwayat gaji Anda.`}
            />

            <Tabs value={tab} onValueChange={setTab}>
                <UnderlineTabsList>
                    <TabsTrigger value="kpi">
                        <Target />
                        KPI
                    </TabsTrigger>
                    <TabsTrigger value="reviews">
                        <ClipboardList />
                        Evaluasi
                    </TabsTrigger>
                    <TabsTrigger value="discipline">
                        <Gavel />
                        Kedisiplinan
                    </TabsTrigger>
                    <TabsTrigger value="salary">
                        <BadgeDollarSign />
                        Gaji
                    </TabsTrigger>
                </UnderlineTabsList>

                <TabsContent value="kpi" className="mt-6">
                    <EmployeeKpiTab employee={employee} data={kpi} canManage={false} selfView />
                </TabsContent>
                <TabsContent value="reviews" className="mt-6">
                    <EmployeeReviewsTab employee={employee} data={reviews} canManage={false} selfView />
                </TabsContent>
                <TabsContent value="discipline" className="mt-6">
                    <EmployeeDisciplineTab employee={employee} data={discipline} canManage={false} selfView />
                </TabsContent>
                <TabsContent value="salary" className="mt-6">
                    <EmployeeSalaryTab employee={employee} data={salary} canManage={false} canDecide={false} selfView />
                </TabsContent>
            </Tabs>
        </AppLayout>
    );
}
