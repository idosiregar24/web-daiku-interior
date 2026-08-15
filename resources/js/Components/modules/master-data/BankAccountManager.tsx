import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import type { BankAccount } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    bank_name: z.string().min(1, 'Nama bank wajib diisi'),
    account_no: z.string().min(1, 'Nomor rekening wajib diisi'),
    label: z.string().min(1, 'Label wajib diisi'),
    balance: z
        .string()
        .refine((v) => v === '' || (!isNaN(Number(v)) && Number(v) >= 0), 'Saldo tidak valid'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(
        Number(value),
    );
}

export function BankAccountManager({ bankAccounts }: { bankAccounts: BankAccount[] }) {
    const [editing, setEditing] = useState<BankAccount | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { bank_name: '', account_no: '', label: '', balance: '0', is_active: true },
    });

    function openCreate() {
        setEditing(null);
        form.reset({ bank_name: '', account_no: '', label: '', balance: '0', is_active: true });
        setOpen(true);
    }

    function openEdit(account: BankAccount) {
        setEditing(account);
        form.reset({
            bank_name: account.bank_name,
            account_no: account.account_no,
            label: account.label,
            balance: String(account.balance),
            is_active: account.is_active,
        });
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };
        const onSuccess = () => setOpen(false);
        const payload = { ...values, balance: Number(values.balance || 0) };

        if (editing) {
            router.put(route('master-data.bank-accounts.update', { bank_account: editing.id }), payload, {
                onError,
                onSuccess,
            });
        } else {
            router.post(route('master-data.bank-accounts.store'), payload, { onError, onSuccess });
        }
    }

    function onDelete(account: BankAccount) {
        if (!confirm(`Hapus rekening "${account.label}"?`)) return;
        router.delete(route('master-data.bank-accounts.destroy', { bank_account: account.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-daiku-muted">
                    Rekening bank perusahaan (PRD §4.7 "Multi-Rekening"). Saldo di sini masih diisi manual —
                    begitu modul Finance dibangun, saldo akan dihitung otomatis dari transaksi.
                </p>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            Tambah Rekening
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editing ? 'Edit Rekening' : 'Tambah Rekening'}</DialogTitle>
                        </DialogHeader>
                        <Form {...form}>
                            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                                <FormField
                                    control={form.control}
                                    name="bank_name"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Nama Bank</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder="mis. BCA" autoFocus />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="account_no"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Nomor Rekening</FormLabel>
                                            <FormControl>
                                                <Input {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="label"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Label</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder='mis. "BCA 5835"' />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="balance"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Saldo Awal (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" step="0.01" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="is_active"
                                    render={({ field }) => (
                                        <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                            <FormLabel className="cursor-pointer">Rekening aktif</FormLabel>
                                            <FormControl>
                                                <Switch checked={field.value} onCheckedChange={field.onChange} />
                                            </FormControl>
                                        </FormItem>
                                    )}
                                />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="outline">
                                            Batal
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={form.formState.isSubmitting}>
                                        Simpan
                                    </Button>
                                </DialogFooter>
                            </form>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>

            {bankAccounts.length === 0 ? (
                <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                    Belum ada rekening bank.
                </p>
            ) : (
                <div className="overflow-hidden rounded-lg border border-daiku-border">
                    <table className="w-full text-sm">
                        <thead className="bg-daiku-yellow-light">
                            <tr>
                                <th className="p-2 text-left font-medium">Label</th>
                                <th className="p-2 text-left font-medium">Bank</th>
                                <th className="p-2 text-left font-medium">No. Rekening</th>
                                <th className="p-2 text-right font-medium">Saldo</th>
                                <th className="p-2 text-left font-medium">Status</th>
                                <th className="w-20 p-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {bankAccounts.map((account) => (
                                <tr key={account.id} className="border-t border-daiku-border">
                                    <td className="p-2 font-medium">{account.label}</td>
                                    <td className="p-2">{account.bank_name}</td>
                                    <td className="p-2 text-daiku-muted">{account.account_no}</td>
                                    <td className="p-2 text-right">{formatRupiah(account.balance)}</td>
                                    <td className="p-2">
                                        <Badge
                                            variant="secondary"
                                            className={
                                                account.is_active
                                                    ? 'bg-success/10 text-success'
                                                    : 'bg-daiku-gray text-daiku-muted'
                                            }
                                        >
                                            {account.is_active ? 'Aktif' : 'Nonaktif'}
                                        </Badge>
                                    </td>
                                    <td className="flex justify-end gap-1 p-2">
                                        <Button variant="ghost" size="icon-sm" onClick={() => openEdit(account)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onDelete(account)}>
                                            <Trash2 className="size-4 text-error" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
