import { BankAccountManager } from '@/Components/modules/master-data/BankAccountManager';
import { BranchManager } from '@/Components/modules/master-data/BranchManager';
import { NameOnlyLookupManager } from '@/Components/modules/master-data/NameOnlyLookupManager';
import { MaterialCategoryManager } from '@/Components/modules/master-data/MaterialCategoryManager';
import { MaterialSynonymManager } from '@/Components/modules/master-data/MaterialSynonymManager';
import { UnitManager } from '@/Components/modules/master-data/UnitManager';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, Branch, LeadCategoryOption, LeadSourceOption, MaterialCategory, MaterialSynonym, UnitRow } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Database } from 'lucide-react';

/** Tab → breadcrumb label. */
const MASTER_TAB_LABEL: Record<string, string> = {
    branches: 'Cabang',
    'lead-sources': 'Sumber Lead',
    'lead-categories': 'Kategori Customer',
    'bank-accounts': 'Rekening Bank',
    units: 'Satuan',
    'material-categories': 'Kategori Material',
    'material-synonyms': 'Sinonim Barang',
};

interface MasterDataIndexProps {
    branches: Branch[];
    leadSources: LeadSourceOption[];
    leadCategories: LeadCategoryOption[];
    bankAccounts: BankAccount[];
    units: UnitRow[];
    materialCategories: MaterialCategory[];
    materialSynonyms: MaterialSynonym[];
}

export default function MasterDataIndex({
    branches,
    leadSources,
    leadCategories,
    bankAccounts,
    units,
    materialCategories,
    materialSynonyms,
}: MasterDataIndexProps) {
    const [tab, setTab] = useState('branches');

    return (
        <AppLayout breadcrumbs={[{ label: MASTER_TAB_LABEL[tab] }]}>
            <Head title="Data Master" />

            <PageHeader
                title="Data Master"
                icon={Database}
                description="Kelola data referensi yang dipakai modul lain — khusus SuperAdmin."
            />

            <ModuleTabs />

            <Card>
                <CardContent className="px-5 py-4 sm:px-6">
                    <Tabs value={tab} onValueChange={setTab}>
                        <TabsList className="scrollbar-thin max-w-full justify-start overflow-x-auto *:flex-none *:px-3">
                            <TabsTrigger value="branches">Cabang</TabsTrigger>
                            <TabsTrigger value="lead-sources">Sumber Lead</TabsTrigger>
                            <TabsTrigger value="lead-categories">Kategori Customer</TabsTrigger>
                            <TabsTrigger value="bank-accounts">Rekening Bank</TabsTrigger>
                            <TabsTrigger value="units">Satuan</TabsTrigger>
                            <TabsTrigger value="material-categories">Kategori Material</TabsTrigger>
                            <TabsTrigger value="material-synonyms">Sinonim Barang</TabsTrigger>
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

                        <TabsContent value="units" className="pt-4">
                            <UnitManager units={units} />
                        </TabsContent>

                        <TabsContent value="material-categories" className="pt-4">
                            <MaterialCategoryManager categories={materialCategories} />
                        </TabsContent>

                        <TabsContent value="material-synonyms" className="pt-4">
                            <MaterialSynonymManager synonyms={materialSynonyms} />
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
