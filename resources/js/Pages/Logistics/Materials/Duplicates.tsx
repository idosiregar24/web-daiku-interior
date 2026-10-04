import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatQty } from '@/lib/format';
import type { Material } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CopyX, GitMerge } from 'lucide-react';
import { useState } from 'react';

type DuplicateMaterial = Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit' | 'stock' | 'category'>;

interface DuplicatesProps {
    groups: { keeper: DuplicateMaterial; duplicates: DuplicateMaterial[] }[];
    materials: Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit' | 'stock'>[];
}

/**
 * Sprint 11 §5.5 Lapis 6 — "Cek Duplikat": items the anti-duplicate key
 * flagged (legacy data, a new synonym) next to the item holding their
 * key, plus a manual merge of any two items with the same unit. Merging
 * moves stock through the ledger and redirects project lines; the merged
 * item stays as an inactive record. Logistics only.
 */
export default function MaterialDuplicates({ groups, materials }: DuplicatesProps) {
    const errors = usePage().props.errors as Record<string, string>;
    const [fromId, setFromId] = useState('');
    const [intoId, setIntoId] = useState('');
    const from = materials.find((material) => String(material.id) === fromId);
    const targets = from ? materials.filter((material) => material.id !== from.id && material.unit_id === from.unit_id) : [];

    function merge(source: Pick<Material, 'id' | 'code' | 'name'>, target: Pick<Material, 'id' | 'code' | 'name'>) {
        if (!confirm(`Gabungkan ${source.code} ${source.name} ke ${target.code} ${target.name}? Stok dan baris material proyek dipindah; ${source.code} menjadi nonaktif.`)) {
            return;
        }

        router.post(
            route('logistics.materials.merge', { material: source.id }),
            { target_id: target.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setFromId('');
                    setIntoId('');
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Cek Duplikat' }]}>
            <Head title="Cek Duplikat Material" />

            <PageHeader
                title="Cek Duplikat"
                icon={CopyX}
                description="Barang yang terdeteksi sama (penulisan berbeda, sinonim) — gabungkan supaya katalog tidak dobel."
            />

            {errors?.target_id && (
                <Notice tone="error" className="mb-6">
                    {errors.target_id}
                </Notice>
            )}

            <div className="space-y-6">
                <SectionCard title="Kemungkinan dobel" description="Kiri: barang yang dipertahankan. Kanan: barang yang terdeteksi sama dengannya." icon={CopyX}>
                    {groups.length === 0 ? (
                        <EmptyState title="Tidak ada barang yang ditandai dobel." />
                    ) : (
                        <TableCard>
                            <table className="w-full text-sm">
                                <thead className={TABLE_HEAD_CLASS}>
                                    <tr>
                                        <th className="px-4 py-2.5 text-left font-semibold">Dipertahankan</th>
                                        <th className="px-4 py-2.5 text-left font-semibold">Terdeteksi sama</th>
                                        <th className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {groups.flatMap((group) =>
                                        group.duplicates.map((duplicate) => (
                                            <tr key={duplicate.id} className="border-t border-border align-top">
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-daiku-dark">
                                                        {group.keeper.code} {group.keeper.name}
                                                    </p>
                                                    <p className="text-xs text-daiku-muted">
                                                        {group.keeper.category?.name} · stok {formatQty(group.keeper.stock)} {group.keeper.unit?.code}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-daiku-dark">
                                                        {duplicate.code} {duplicate.name}
                                                    </p>
                                                    <p className="text-xs text-daiku-muted">
                                                        {duplicate.category?.name} · stok {formatQty(duplicate.stock)} {duplicate.unit?.code}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Button size="sm" onClick={() => merge(duplicate, group.keeper)}>
                                                        <GitMerge className="size-4" />
                                                        Gabung ke {group.keeper.code}
                                                    </Button>
                                                </td>
                                            </tr>
                                        )),
                                    )}
                                </tbody>
                            </table>
                        </TableCard>
                    )}
                </SectionCard>

                <SectionCard title="Gabung manual" description="Untuk dobel yang lolos (mis. dibuat dengan alasan). Hanya barang dengan satuan sama." icon={GitMerge}>
                    <div className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <div className="space-y-2">
                            <Label>Barang yang digabung (jadi nonaktif)</Label>
                            <Select
                                value={fromId}
                                onValueChange={(value) => {
                                    setFromId(value);
                                    setIntoId('');
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Pilih barang" />
                                </SelectTrigger>
                                <SelectContent>
                                    {materials.map((material) => (
                                        <SelectItem key={material.id} value={String(material.id)}>
                                            {material.code} {material.name} ({material.unit?.code})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Digabung ke (dipertahankan)</Label>
                            <Select value={intoId} onValueChange={setIntoId} disabled={!from}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder={from ? 'Pilih barang tujuan' : 'Pilih barang asal dulu'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {targets.map((material) => (
                                        <SelectItem key={material.id} value={String(material.id)}>
                                            {material.code} {material.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            disabled={!from || !intoId}
                            onClick={() => {
                                const target = targets.find((material) => String(material.id) === intoId);
                                if (from && target) merge(from, target);
                            }}
                        >
                            <GitMerge className="size-4" />
                            Gabungkan
                        </Button>
                    </div>
                </SectionCard>
            </div>
        </AppLayout>
    );
}
