import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props;

    return (
        <AppLayout header={<h2 className="text-base font-semibold text-daiku-dark">Dashboard</h2>}>
            <Head title="Dashboard" />

            <Card>
                <CardHeader>
                    <CardTitle>Selamat datang, {auth.user.name}</CardTitle>
                </CardHeader>
                <CardContent className="text-sm text-daiku-muted">
                    Ini adalah fondasi Daiku Interior Enterprise System — CRM,
                    Desain, Quotation, Project Management, Finance, dan modul
                    lainnya akan dibangun di atas struktur ini pada fase
                    berikutnya (lihat PRD bagian 10).
                </CardContent>
            </Card>
        </AppLayout>
    );
}
