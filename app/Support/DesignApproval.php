<?php

namespace App\Support;

use App\Models\Design;
use App\Models\Quotation;

/**
 * Sprint 17 Sub 04 — what DesignService::markClientApproved() did, so the
 * page can say it honestly: a RAB Proyek was requested automatically
 * (`requested`), one was already running (`running`), or neither (the lead
 * is LOST/CLOSING).
 */
final readonly class DesignApproval
{
    public function __construct(
        public Design $design,
        public ?Quotation $requested = null,
        public ?Quotation $running = null,
    ) {}

    /** The flash message after "Desain Disetujui Klien". */
    public function message(): string
    {
        return match (true) {
            $this->requested !== null => 'Desain disetujui klien. Permintaan RAB Proyek otomatis dikirim ke Estimator.',
            $this->running !== null => "Desain disetujui klien. {$this->running->title()} untuk klien ini sudah berjalan ({$this->statusLabel($this->running)}) — tidak diminta ulang.",
            default => 'Desain disetujui klien.',
        };
    }

    private function statusLabel(Quotation $quotation): string
    {
        return str_replace('_', ' ', $quotation->status->value);
    }
}
