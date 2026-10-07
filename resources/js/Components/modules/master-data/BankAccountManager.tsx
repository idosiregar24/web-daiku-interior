import { EmptyState } from '@/Components/shared/EmptyState';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
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
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import { formatRupiah } from '@/lib/format';
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
    // Mirrors StoreBankAccountRequest/UpdateBankAccountRequest.
    opening_balance: z
        .string()
        .refine((v) => v === '' || !isNaN(Number(v)), 'Saldo awal harus berupa angka')
        .refine((v) => v === '' || Number(v) >= 0, 'Saldo awal tidak boleh negatif')
        .refine((v) => v === '' || Number(v) <= 9_999_999_999_999.99, 'Saldo awal terlalu besar'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

export function BankAccountManager({ bankAccounts }: { bankAccounts: BankAccount[] }) {
    const [editing, setEditing] = useState<BankAccount | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { bank_name: '', account_no: '', label: '', opening_balance: '0', is_active: true },
    });

    function openCreate() {
        setEditing(null);
        form.reset({ bank_name: '', account_no: '', label: '', opening_balance: '0', is_active: true });
        setOpen(true);
    }

    function openEdit(account: BankAccount) {
        setEditing(account);
        form.reset({
            bank_name: account.bank_name,
            account_no: account.account_no,
            label: account.label,
            opening_balance: String(Number(account.opening_balance)),
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
        const payload = { ...values, opening_balance: Number(values.opening_balance || 0) };

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
            <div className="mb-4 flex items-center justify-between gap-4">
                <p className="text-sm text-daiku-muted">
                    Rekening bank perusahaan (PRD §4.7 "Multi-Rekening"). Saldo saat ini dihitung otomatis: saldo awal +
                    pemasukan − pengeluaran yang tercatat di Finance. Rekening yang sudah bertransaksi dinonaktifkan,
                    tidak dihapus.
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
                                            <FormLabel required>Nama Bank</FormLabel>
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
                                            <FormLabel required>Nomor Rekening</FormLabel>
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
                                            <FormLabel required>Label</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder='mis. "BCA 5835"' />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="opening_balance"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Saldo Awal (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="0.01" {...field} />
                                            </FormControl>
                                            <FormDescription>
                                                Saldo sebelum transaksi pertama yang dicatat di sistem. Perubahannya tercatat
                                                di Audit Log.
                                            </FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                {editing?.current_balance !== undefined && (
                                    <p className="text-sm text-daiku-muted">
                                        Saldo saat ini:{' '}
                                        <span className="font-medium text-foreground">
                                            {formatRupiah(editing.current_balance)}
                                        </span>
                                    </p>
                                )}
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
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada rekening bank." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Label</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Bank</th>
                                <th className="px-4 py-2.5 text-left font-semibold">No. Rekening</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Saldo Awal</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Saldo Saat Ini</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                <th className="w-20 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {bankAccounts.map((account) => (
                                <tr key={account.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{account.label}</td>
                                    <td className="px-4 py-3">{account.bank_name}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{account.account_no}</td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap text-daiku-muted tabular-nums">
                                        {formatRupiah(account.opening_balance)}
                                    </td>
                                    <td className="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">
                                        {account.current_balance !== undefined ? formatRupiah(account.current_balance) : '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusChip
                                            status={account.is_active ? 'ACTIVE' : 'INACTIVE'}
                                            label={account.is_active ? 'Aktif' : 'Nonaktif'}
                                            tone={account.is_active ? 'success' : 'neutral'}
                                        />
                                    </td>
                                    <td className="flex justify-end gap-1 px-4 py-3">
                                        <Button variant="ghost" size="icon-sm" onClick={() => openEdit(account)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onDelete(account)}>
                                            <Trash2 className="size-4 text-error-ink" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableCard>
            )}
        </div>
    );
}
