import { Button } from '@/Components/ui/button';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formatQty } from '@/lib/format';
import type { ProjectMaterial } from '@/types';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { RequiredMark } from '@/Components/shared/RequiredMark';
import { useMaterialVisit } from './partialVisit';

interface PmRequestDecisionDialogProps {
    line: ProjectMaterial | null;
    decision: 'approve' | 'reject' | null;
    onOpenChange: (open: boolean) => void;
    /**
     * Sprint 29 Sub 01 (L9) — the host page's props to reload after the decision. Omitted on
     * Detail Proyek = `RELOAD.materials`; omitted elsewhere = full reload.
     */
    only?: readonly string[];
}

/**
 * Sprint 11 Sub 4 — the project's PM approves a Tukang's request (it then
 * goes to Logistics) or rejects it with a reason. Mirrors
 * PmMaterialRequestDecisionRequest.
 */
export function PmRequestDecisionDialog({ line, decision, onOpenChange, only }: PmRequestDecisionDialogProps) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    // A decision — keeps the pop-up, reloads only what it changed.
    const visit = useMaterialVisit(only);

    useEffect(() => {
        setReason('');
        setError(null);
    }, [line?.id, decision]);

    if (!line || !decision) {
        return null;
    }

    const approve = decision === 'approve';

    function submit() {
        if (!line) return;
        if (!approve && !reason.trim()) {
            setError('Alasan penolakan wajib diisi.');
            return;
        }

        router.post(
            route('project-materials.pmDecision', { project_material: line.id }),
            { decision, reason: approve ? null : reason.trim() },
            {
                ...visit,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.reason ?? errors.decision ?? 'Gagal menyimpan keputusan.'),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{approve ? 'Setujui Pengajuan' : 'Tolak Pengajuan'}</DialogTitle>
                    <DialogDescription>
                        {line.requester?.name ?? 'Tukang'} mengajukan {line.display_name} ({formatQty(line.qty_planned)}).
                        {line.request_reason && ` Catatan: “${line.request_reason}”.`}{' '}
                        {approve ? 'Pengajuan akan diteruskan ke Logistik.' : 'Tukang akan menerima alasan penolakan.'}
                    </DialogDescription>
                </DialogHeader>
                {!approve && (
                    <div className="space-y-2">
                        <Label htmlFor="pm-reject-reason">Alasan penolakan<RequiredMark /></Label>
                        <Textarea id="pm-reject-reason" rows={3} value={reason} onChange={(event) => setReason(event.target.value)} />
                    </div>
                )}
                {error && <p className="text-sm text-destructive">{error}</p>}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button type="button" variant={approve ? 'default' : 'destructive'} onClick={submit} disabled={processing}>
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        {approve ? 'Setujui & Teruskan' : 'Tolak Pengajuan'}
                    </Button>
                </DialogFooter>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
