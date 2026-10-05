import type { PaymentTermTrigger, QuotationType } from '@/types';

/** App\Enums\QuotationType::label() — Sprint 12 #6. */
export const QUOTATION_TYPE_LABEL: Record<QuotationType, string> = {
    SURVEY: 'RAB Jasa Survey',
    DESAIN: 'RAB Jasa Desain',
    PROYEK: 'RAB Proyek',
};

/** App\Enums\PaymentTermTrigger::label() — Sprint 12 #12. */
export const PAYMENT_TRIGGER_LABEL: Record<PaymentTermTrigger, string> = {
    DI_MUKA: 'Di muka (saat disetujui)',
    TANGGAL: 'Tanggal tertentu',
    MILESTONE: 'Milestone selesai',
    PROYEK_SELESAI: 'Proyek selesai',
};
