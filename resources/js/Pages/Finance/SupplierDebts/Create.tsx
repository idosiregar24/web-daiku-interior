import { DatePicker } from '@/Components/shared/DatePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { Project } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { format } from 'date-fns';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\Finance\StoreSupplierDebtRequest.
const schema = z.object({
    supplier_name: z.string().trim().min(1, 'Nama supplier wajib diisi.').max(100, 'Nama supplier maksimal 100 karakter.'),
    total_amount: z
        .string()
        .min(1, 'Total hutang wajib diisi.')
        .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Total hutang harus lebih dari 0.'),
    project_id: z.string().optional(),
    due_date: z.date().optional(),
    description: z.string().max(2000, 'Keterangan maksimal 2000 karakter.').optional(),
});

type FormValues = z.infer<typeof schema>;

interface SupplierDebtCreateProps {
    projects: Pick<Project, 'id' | 'name'>[];
}

/**
 * PRD §4.7 "Hutang Supplier" — Finance only. Creating a debt records the
 * liability only; cash leaves a bank account when each payment is recorded.
 */
export default function SupplierDebtCreate({ projects }: SupplierDebtCreateProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { supplier_name: '', total_amount: '', project_id: '', due_date: undefined, description: '' },
    });

    function onSubmit(values: FormValues) {
        router.post(
            route('finance.supplierDebts.store'),
            {
                supplier_name: values.supplier_name,
                total_amount: Number(values.total_amount),
                project_id: values.project_id ? Number(values.project_id) : null,
                due_date: values.due_date ? format(values.due_date, 'yyyy-MM-dd') : null,
                description: values.description || null,
            },
            {
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field as keyof FormValues, { message });
                    });
                },
            },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[{ label: 'Catat Hutang' }]}
        >
            <Head title="Catat Hutang Supplier" />

            <PageHeader
                title="Catat Hutang Supplier"
                icon={Receipt}
                description="Hutang baru dicatat sebagai kewajiban — pengeluaran kas tercatat saat pembayaran."
            />

            <Card className="max-w-2xl">
                <CardContent className="px-5 py-2 sm:px-6">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="supplier_name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nama Supplier</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="mis. Ideal, Kaca Jaya" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    control={form.control}
                                    name="total_amount"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Total Hutang (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="0.01" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="due_date"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Jatuh Tempo (opsional)</FormLabel>
                                            <FormControl>
                                                <DatePicker
                                                    value={field.value}
                                                    onChange={field.onChange}
                                                    placeholder="Pilih tanggal"
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            <FormField
                                control={form.control}
                                name="project_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Proyek (opsional)</FormLabel>
                                        <Select
                                            value={field.value || 'none'}
                                            onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih proyek" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="none">Tanpa proyek</SelectItem>
                                                {projects.map((project) => (
                                                    <SelectItem key={project.id} value={String(project.id)}>
                                                        {project.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="description"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Keterangan (opsional)</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={3} placeholder="mis. Kaca tempered 8mm untuk partisi" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('finance.supplierDebts.index')}>Batal</Link>
                                </Button>
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan
                                </Button>
                            </div>
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
