import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
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
import { useEffect, useState } from 'react';

interface PmRequestDecisionDialogProps {
    line: ProjectMaterial | null;
    decision: 'approve' | 'reject' | null;
    onOpenChange: (open: boolean) => void;
}

/**
 * Sprint 11 Sub 4 — the project's PM approves a Tukang's request (it then
 * goes to Logistics) or rejects it with a reason. Mirrors
 * PmMaterialRequestDecisionRequest.
 */
export function PmRequestDecisionDialog({ line, decision, onOpenChange }: PmRequestDecisionDialogProps) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

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
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.reason ?? errors.decision ?? 'Gagal menyimpan keputusan.'),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
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
                        <Label htmlFor="pm-reject-reason">Alasan penolakan</Label>
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
                        {approve ? 'Setujui & Teruskan' : 'Tolak Pengajuan'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
