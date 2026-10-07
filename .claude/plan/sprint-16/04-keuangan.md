# Sprint 16 · 04 — Keuangan

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K2, K4–K9
> Status: **selesai 2026-10-07** · Butuh Sub 01

Cara kerja sama dengan Sub 02. Hampir semua dialog pembayaran punya
"Catatan (opsional)", dan label itu dihapus sesuai K4.

## Checklist
### Dialog
- [x] `Components/modules/finance/TransactionFormDialog.tsx`
- [x] `Components/modules/finance/TerminPaymentDialog.tsx`
- [x] `Components/modules/finance/InvoiceDialogs.tsx` (terbit oleh
      Marketing, verifikasi oleh Finance; aturan per dialog)
- [x] `Components/modules/finance/FundTransferDialog.tsx`
- [x] `Components/modules/finance/FundExpenseDialog.tsx`
- [x] `Components/modules/finance/AllocationFormDialog.tsx`
- [x] `Components/modules/finance/AssetInstallmentPaymentDialog.tsx`
- [x] `Components/modules/finance/PenaltyPaymentDialog.tsx`: daftar
      penalti dipilih minimal 1, jadi bintang di label grup
- [x] `Components/modules/finance/SalaryPaymentDialog.tsx`
- [x] `Components/modules/finance/SupplierDebtPaymentDialog.tsx`
- [x] `Components/modules/analytics/RevenueTargetDialog.tsx`

### Halaman
- [x] `Pages/Finance/StaffLoans/Create.tsx`
- [x] `Pages/Finance/StaffLoans/Show.tsx`
- [x] `Pages/Finance/StaffPayments/Index.tsx`
- [x] `Pages/Finance/SupplierDebts/Create.tsx`

### Selesai
- [x] `npm run build` lulus · cek di browser: TransactionFormDialog,
      InvoiceDialogs, StaffLoans/Create

## Catatan pelaksanaan (2026-10-07)
- Semua kolom nominal/tanggal/rekening pembayaran `required` → bintang;
  10 label "(opsional)" dihapus (Catatan, Keterangan, Proyek, Jatuh Tempo
  hutang supplier).
- Penalti: bintang di label grup "Penalti yang dibayar" (`penalty_ids`
  `required|min:1`). Penggajian: Tunjangan/Potongan `nullable` → tanpa bintang.
- Alokasi: `UpdateFinanceAllocationConfigRequest` mewarisi aturan Store →
  Label, Persentase, Kategori berbintang di tambah & ubah; "Alokasi aktif"
  (switch) tanpa bintang (K6).
- Zod ≠ server: tidak ditemukan pada pemeriksaan sampel (transaksi, gaji).
