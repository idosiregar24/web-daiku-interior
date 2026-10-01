import { Notice } from '@/Components/shared/Notice';
import { formatDate } from '@/lib/format';
import type { Quotation } from '@/types';
import { startOfDay, startOfToday } from 'date-fns';

type QuotationValidity = Pick<Quotation, 'status' | 'valid_until'>;

/**
 * PRD §4.3 "Validity Period" — an offer still waiting on the client
 * (SENT_TO_CLIENT) whose `valid_until` day has passed. The offer is valid
 * through the whole of that day.
 */
export function isQuotationExpired(quotation: QuotationValidity | null | undefined): boolean {
    if (!quotation || quotation.status !== 'SENT_TO_CLIENT' || !quotation.valid_until) {
        return false;
    }

    return startOfDay(new Date(quotation.valid_until)) < startOfToday();
}

interface QuotationExpiryNoticeProps {
    quotation: QuotationValidity | null | undefined;
    className?: string;
}

/**
 * Warning only (Sprint 9 decision #3) — an expired offer can still be
 * confirmed as a deal or rejected; the price just needs re-confirming
 * with the client first. Renders nothing while the offer is valid.
 */
export function QuotationExpiryNotice({ quotation, className }: QuotationExpiryNoticeProps) {
    if (!quotation || !isQuotationExpired(quotation)) {
        return null;
    }

    return (
        <Notice tone="warning" className={className}>
            Masa berlaku penawaran sudah habis (berlaku sampai {formatDate(quotation.valid_until)}). Deal tetap bisa
            dikonfirmasi — pastikan klien masih menyepakati harga di penawaran ini.
        </Notice>
    );
}
