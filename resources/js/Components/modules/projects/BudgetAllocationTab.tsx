import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import type { BudgetLogEntry, BudgetPost, ProjectBudget } from '@/types';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, FolderPlus, History, Layers, ListTodo, MoreHorizontal, PenLine, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { BudgetPostDialog } from './BudgetPostDialog';

interface BudgetAllocationTabProps {
    projectId: number;
    budget: ProjectBudget;
    /** The project's own PM, project not closed (ProjectPolicy::manageBudget()). */
    canManage: boolean;
}

function quantity(qty: number, unit: string | null) {
    return `${qty.toLocaleString('id-ID')}${unit ? ` ${unit}` : ''}`;
}

function describeLog(log: BudgetLogEntry): string {
    const before = (log.before ?? {}) as { post?: string; items?: { item: string; post: string | null }[] };
    const after = (log.after ?? {}) as { post?: string; items?: string[] };

    switch (log.action) {
        case 'post_created':
            return `Membuat pos "${after.post}"`;
        case 'post_renamed':
            return `Mengganti nama pos "${before.post}" menjadi "${after.post}"`;
        case 'posts_reordered':
            return 'Mengubah urutan pos';
        case 'post_deleted':
            return `Menghapus pos "${before.post}"`;
        case 'items_allocated':
            return `Memasukkan ${after.items?.length ?? 0} item ke pos "${after.post}": ${(after.items ?? []).join(', ')}`;
        case 'items_unallocated':
            return `Mengeluarkan ${before.items?.length ?? 0} item dari pos: ${(before.items ?? []).map((entry) => entry.item).join(', ')}`;
    }
}

/**
 * Sprint 12 decisions #23–#26 — "Alokasi Dana Proyek" (Model A, like the
 * Excel sheets): the PM puts RAB Fix items into freely named posts so
 * Finance sees where the money goes. Opens after the first verified
 * payment; a post total above the RAB only warns; the discount stays a
 * deduction in the summary. CEO / Finance read it, only the project's PM
 * changes it, every change lands in the history.
 */
export function BudgetAllocationTab({ projectId, budget, canManage }: BudgetAllocationTabProps) {
    const [selected, setSelected] = useState<number[]>([]);
    const [targetPost, setTargetPost] = useState('');
    const [postDialog, setPostDialog] = useState<{ open: boolean; post: Pick<BudgetPost, 'id' | 'name'> | null }>({ open: false, post: null });
    const { summary, posts } = budget;

    if (!budget.hasRab) {
        return <Notice tone="info">Proyek ini belum tertaut ke RAB Fix, jadi tidak ada item untuk dialokasikan.</Notice>;
    }

    if (!budget.isOpen) {
        return <Notice tone="info">Alokasi dibuka setelah pembayaran pertama diverifikasi Finance.</Notice>;
    }

    function allocate(itemIds: number[], postId: number | null) {
        router.post(
            route('projects.budget.allocate', { project: projectId }),
            { budget_post_id: postId, item_ids: itemIds },
            { preserveScroll: true, onSuccess: () => setSelected((current) => current.filter((id) => !itemIds.includes(id))) },
        );
    }

    function move(index: number, direction: -1 | 1) {
        const ids = posts.map((post) => post.id);
        [ids[index], ids[index + direction]] = [ids[index + direction], ids[index]];
        router.put(route('projects.budget.posts.reorder', { project: projectId }), { post_ids: ids }, { preserveScroll: true });
    }

    function removePost(post: BudgetPost) {
        router.delete(route('projects.budget.posts.destroy', { project: projectId, post: post.id }), { preserveScroll: true });
    }

    const allSelected = budget.unallocatedItems.length > 0 && selected.length === budget.unallocatedItems.length;

    return (
        <div className="space-y-6">
            <SectionCard title="Ringkasan Alokasi" icon={Layers}>
                <DetailList className="sm:grid-cols-3">
                    <DetailItem label="Total item RAB">{formatRupiah(summary.itemsTotal)}</DetailItem>
                    <DetailItem label="Diskon (pengurang, tidak dibebankan ke pos)">
                        {summary.discount > 0 ? `− ${formatRupiah(summary.discount)}` : '—'}
                    </DetailItem>
                    <DetailItem label="Pembulatan">{summary.rounding !== 0 ? formatRupiah(summary.rounding) : '—'}</DetailItem>
                    <DetailItem label="Total RAB" valueClassName="font-semibold">
                        {formatRupiah(summary.rabTotal)}
                    </DetailItem>
                    <DetailItem label="Total semua pos" valueClassName={summary.overRab ? 'font-semibold text-warning-ink' : 'font-semibold'}>
                        {formatRupiah(summary.postsTotal)}
                    </DetailItem>
                    <DetailItem label="Belum dialokasikan">{formatRupiah(summary.unallocatedTotal)}</DetailItem>
                </DetailList>
                {summary.overRab && (
                    <Notice tone="warning" className="mt-4">
                        Total pos ({formatRupiah(summary.postsTotal)}) melebihi total RAB ({formatRupiah(summary.rabTotal)}) — diskon tidak
                        ikut dibebankan ke pos. Periksa kembali alokasinya.
                    </Notice>
                )}
            </SectionCard>

            <div className="grid gap-6 lg:grid-cols-5">
                <SectionCard
                    title="Item Belum Dialokasikan"
                    icon={ListTodo}
                    description={`${budget.unallocatedItems.length} item · ${formatRupiah(summary.unallocatedTotal)}`}
                    className="lg:col-span-2"
                    flush
                    footer={
                        canManage && budget.unallocatedItems.length > 0 ? (
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Select value={targetPost} onValueChange={setTargetPost}>
                                    <SelectTrigger className="w-full sm:flex-1" aria-label="Pos tujuan">
                                        <SelectValue placeholder={posts.length ? 'Pilih pos' : 'Buat pos dulu'} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {posts.map((post) => (
                                            <SelectItem key={post.id} value={String(post.id)}>
                                                {post.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    disabled={selected.length === 0 || !targetPost}
                                    onClick={() => allocate(selected, Number(targetPost))}
                                >
                                    Masukkan {selected.length > 0 ? `${selected.length} item` : ''}
                                </Button>
                            </div>
                        ) : undefined
                    }
                >
                    {budget.unallocatedItems.length === 0 ? (
                        <EmptyState title="Semua item sudah dialokasikan" className="py-8" />
                    ) : (
                        <ul className="divide-y divide-border">
                            {canManage && (
                                <li className="flex items-center gap-3 px-4 py-2 text-xs text-daiku-muted sm:px-5">
                                    <Checkbox
                                        checked={allSelected}
                                        onCheckedChange={(checked) => setSelected(checked ? budget.unallocatedItems.map((item) => item.id) : [])}
                                        aria-label="Pilih semua item"
                                    />
                                    Pilih semua
                                </li>
                            )}
                            {budget.unallocatedItems.map((item) => (
                                <li key={item.id} className="flex items-start gap-3 px-4 py-3 text-sm sm:px-5">
                                    {canManage && (
                                        <Checkbox
                                            className="mt-0.5"
                                            checked={selected.includes(item.id)}
                                            onCheckedChange={(checked) =>
                                                setSelected((current) => (checked ? [...current, item.id] : current.filter((id) => id !== item.id)))
                                            }
                                            aria-label={`Pilih ${item.description}`}
                                        />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-daiku-dark">{item.description}</p>
                                        <p className="text-xs text-daiku-muted">
                                            {item.section ? `${item.section} · ` : ''}
                                            {quantity(item.qty, item.unit)}
                                        </p>
                                    </div>
                                    <span className="shrink-0 tabular-nums">{formatRupiah(item.total_price)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <div className="space-y-4 lg:col-span-3">
                    <div className="flex items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold text-daiku-dark">Pos Anggaran</h3>
                        {canManage && (
                            <Button size="sm" variant="outline" onClick={() => setPostDialog({ open: true, post: null })}>
                                <FolderPlus className="size-4" />
                                Tambah Pos
                            </Button>
                        )}
                    </div>

                    {posts.length === 0 ? (
                        <EmptyState
                            title="Belum ada pos"
                            description={canManage ? 'Buat pos (mis. Interior, Listrik) lalu masukkan item RAB ke dalamnya.' : 'PM belum membuat pos anggaran.'}
                        />
                    ) : (
                        posts.map((post, index) => (
                            <SectionCard
                                key={post.id}
                                title={post.name}
                                description={`${post.lines.length} item · ${formatRupiah(post.total)}`}
                                flush
                                action={
                                    canManage ? (
                                        <div className="flex items-center gap-1">
                                            <Button size="icon-sm" variant="ghost" disabled={index === 0} onClick={() => move(index, -1)} aria-label={`Naikkan ${post.name}`}>
                                                <ArrowUp className="size-4" />
                                            </Button>
                                            <Button
                                                size="icon-sm"
                                                variant="ghost"
                                                disabled={index === posts.length - 1}
                                                onClick={() => move(index, 1)}
                                                aria-label={`Turunkan ${post.name}`}
                                            >
                                                <ArrowDown className="size-4" />
                                            </Button>
                                            <Button size="icon-sm" variant="ghost" onClick={() => setPostDialog({ open: true, post })} aria-label={`Ubah nama ${post.name}`}>
                                                <PenLine className="size-4" />
                                            </Button>
                                            <Button
                                                size="icon-sm"
                                                variant="ghost"
                                                disabled={post.lines.length > 0}
                                                title={post.lines.length > 0 ? 'Pindahkan dulu itemnya' : undefined}
                                                onClick={() => removePost(post)}
                                                aria-label={`Hapus ${post.name}`}
                                            >
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        </div>
                                    ) : undefined
                                }
                            >
                                {post.lines.length === 0 ? (
                                    <p className="px-4 py-3 text-sm text-daiku-muted sm:px-5">Pos ini masih kosong.</p>
                                ) : (
                                    <ul className="divide-y divide-border">
                                        {post.lines.map((line) => (
                                            <li key={line.id} className="flex items-start gap-3 px-4 py-3 text-sm sm:px-5">
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium text-daiku-dark">{line.description}</p>
                                                    <p className="text-xs text-daiku-muted">
                                                        {quantity(line.qty, line.unit)} × {formatRupiah(line.unit_price)}
                                                    </p>
                                                </div>
                                                <span className="shrink-0 tabular-nums">{formatRupiah(line.sell_price)}</span>
                                                {canManage && line.quotation_item_id !== null && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button size="icon-sm" variant="ghost" aria-label={`Aksi ${line.description}`}>
                                                                <MoreHorizontal className="size-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            {posts.length > 1 && <DropdownMenuLabel>Pindah ke pos</DropdownMenuLabel>}
                                                            {posts
                                                                .filter((other) => other.id !== post.id)
                                                                .map((other) => (
                                                                    <DropdownMenuItem key={other.id} onSelect={() => allocate([line.quotation_item_id!], other.id)}>
                                                                        {other.name}
                                                                    </DropdownMenuItem>
                                                                ))}
                                                            {posts.length > 1 && <DropdownMenuSeparator />}
                                                            <DropdownMenuItem onSelect={() => allocate([line.quotation_item_id!], null)}>
                                                                Keluarkan dari pos
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </SectionCard>
                        ))
                    )}
                </div>
            </div>

            <SectionCard title="Riwayat Alokasi" icon={History} flush>
                {budget.logs.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-daiku-muted sm:px-5">Belum ada perubahan.</p>
                ) : (
                    <ul className="divide-y divide-border">
                        {budget.logs.map((log) => (
                            <li key={log.id} className="flex flex-col gap-0.5 px-4 py-2.5 text-sm sm:flex-row sm:items-baseline sm:justify-between sm:gap-4 sm:px-5">
                                <span className="text-daiku-dark">{describeLog(log)}</span>
                                <span className="shrink-0 text-xs text-daiku-muted">
                                    {log.user_name ?? '—'} · {new Date(log.created_at).toLocaleString('id-ID')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>

            {canManage && (
                <BudgetPostDialog
                    open={postDialog.open}
                    onOpenChange={(open) => setPostDialog((current) => ({ ...current, open }))}
                    projectId={projectId}
                    post={postDialog.post}
                />
            )}
        </div>
    );
}
