import { BankAccountManager } from '@/Components/modules/master-data/BankAccountManager';
import { BranchManager } from '@/Components/modules/master-data/BranchManager';
import { NameOnlyLookupManager } from '@/Components/modules/master-data/NameOnlyLookupManager';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, Branch, LeadCategoryOption, LeadSourceOption } from '@/types';
import { Head } from '@inertiajs/react';

interface MasterDataIndexProps {
    branches: Branch[];
    leadSources: LeadSourceOption[];
    leadCategories: LeadCategoryOption[];
    bankAccounts: BankAccount[];
}

export default function MasterDataIndex({
    branches,
    leadSources,
    leadCategories,
    bankAccounts,
}: MasterDataIndexProps) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Sistem' }, { label: 'Data Master' }]}>
            <Head title="Data Master" />

            <PageHeader
                title="Data Master"
                description="Kelola data referensi yang dipakai modul lain — khusus SuperAdmin."
            />

            <Card>
                <CardContent className="pt-6">
                    <Tabs defaultValue="branches">
                        <TabsList>
                            <TabsTrigger value="branches">Cabang</TabsTrigger>
                            <TabsTrigger value="lead-sources">Sumber Lead</TabsTrigger>
                            <TabsTrigger value="lead-categories">Kategori Customer</TabsTrigger>
                            <TabsTrigger value="bank-accounts">Rekening Bank</TabsTrigger>
                        </TabsList>

                        <TabsContent value="branches" className="pt-4">
                            <BranchManager branches={branches} />
                        </TabsContent>

                        <TabsContent value="lead-sources" className="pt-4">
                            <NameOnlyLookupManager
                                title="Sumber Lead"
                                description="Sumber lead yang bisa dipilih di form CRM (Instagram, Referral, dll)."
                                addLabel="Tambah Sumber"
                                items={leadSources}
                                storeRouteName="master-data.lead-sources.store"
                                updateRouteName="master-data.lead-sources.update"
                                destroyRouteName="master-data.lead-sources.destroy"
                                routeParam="lead_source"
                                emptyMessage="Belum ada sumber lead."
                            />
                        </TabsContent>

                        <TabsContent value="lead-categories" className="pt-4">
                            <NameOnlyLookupManager
                                title="Kategori Customer"
                                description="Kategori customer yang bisa dipilih di form CRM (Residential, Komersial, dll)."
                                addLabel="Tambah Kategori"
                                items={leadCategories}
                                storeRouteName="master-data.lead-categories.store"
                                updateRouteName="master-data.lead-categories.update"
                                destroyRouteName="master-data.lead-categories.destroy"
                                routeParam="lead_category"
                                emptyMessage="Belum ada kategori."
                            />
                        </TabsContent>

                        <TabsContent value="bank-accounts" className="pt-4">
                            <BankAccountManager bankAccounts={bankAccounts} />
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
