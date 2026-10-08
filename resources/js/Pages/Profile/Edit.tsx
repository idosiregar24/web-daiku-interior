import { PageHeader } from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
    usernameChangeableAt,
    usernameChangeDays,
}: PageProps<{ mustVerifyEmail: boolean; status?: string; usernameChangeableAt: string | null; usernameChangeDays: number }>) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Profil Saya' }]}>
            <Head title="Profile" />

            <PageHeader
                title="Profil Saya"
                description="Kelola informasi akun dan keamanan login Anda."
                icon={UserRound}
            />

            <div className="grid max-w-4xl gap-6">
                <Card>
                    <CardContent className="p-2 sm:p-4">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            usernameChangeableAt={usernameChangeableAt}
                            usernameChangeDays={usernameChangeDays}
                            className="max-w-xl"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-2 sm:p-4">
                        <UpdatePasswordForm className="max-w-xl" />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
