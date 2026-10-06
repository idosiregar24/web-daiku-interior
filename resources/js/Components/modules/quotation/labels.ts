import type { PaymentTermTrigger, Quotation, QuotationType } from '@/types';

/** App\Enums\QuotationType::label() — Sprint 12 #6. */
export const QUOTATION_TYPE_LABEL: Record<QuotationType, string> = {
    SURVEY: 'RAB Jasa Survey',
    DESAIN: 'RAB Jasa Desain',
    PROYEK: 'RAB Proyek',
};

/**
 * What a RAB is called — mirrors App\Models\Quotation::title(): its custom
 * name (Sprint 14, prefixed "RAB " when missing), "RAB Tambahan" for an
 * addendum, else the type's label.
 */
export function quotationTitle(quotation: Pick<Quotation, 'type'> & Partial<Pick<Quotation, 'custom_name' | 'parent_quotation_id'>>): string {
    const custom = quotation.custom_name?.trim();

    if (custom) return /^rab\b/i.test(custom) ? custom : `RAB ${custom}`;
    if (quotation.parent_quotation_id) return 'RAB Tambahan';

    return QUOTATION_TYPE_LABEL[quotation.type];
}

/** App\Enums\PaymentTermTrigger::label() — Sprint 12 #12. */
export const PAYMENT_TRIGGER_LABEL: Record<PaymentTermTrigger, string> = {
    DI_MUKA: 'Di muka (saat disetujui)',
    TANGGAL: 'Tanggal tertentu',
    MILESTONE: 'Milestone selesai',
    PROYEK_SELESAI: 'Proyek selesai',
};
