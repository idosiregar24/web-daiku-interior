# Sprint 12 · 09 — Alokasi Dana Proyek (pos anggaran, Model A)

> Induk: [`../sprint-12-revisi-alur.md`](../sprint-12-revisi-alur.md).
> Status: **selesai 2026-10-05** · Prasyarat: 07 · Keputusan: #23–#26

## Tujuan
Setelah pembayaran pertama terverifikasi, PM mengelompokkan item RAB ke pos
dengan nama bebas (seperti Excel Kopi OZ: Interior, Listrik, Percetakan,
Mural), supaya Finance tahu uang dialokasikan ke mana.

> ⚠️ Nama bentrok: sudah ada **"Alokasi"** di Finance (`FinanceAllocationService`,
> `Pages/Finance/Allocations`, `finance_allocation_configs` — alokasi %
> pemasukan perusahaan, Sprint 8). Fitur ini diberi nama kode **ProjectBudget**
> dan label UI **"Alokasi Dana Proyek"** agar tidak tertukar.

## File yang disentuh
- Baru: `ProjectBudgetService`, model `BudgetPost`/`BudgetLine`/`BudgetAllocationLog`,
  `Projects/ProjectBudgetController`, requests
- `app/Policies/ProjectPolicy.php` (ability `manageBudget` = PM proyek itu)
- `app/Http/Controllers/Projects/ProjectController.php` (`show` — props tab baru)
- `resources/js/Pages/Projects/Show.tsx` (tab baru) + komponen di `Components/modules/projects/`
- Test: `tests/Feature/Projects/*`

## Rancangan
```
budget_posts            project_id, name, sort_order, created_by
budget_lines            budget_post_id, quotation_item_id (nullable, UNIQUE),
                        description, qty, unit_id, sell_price (anggaran, disalin
                        dari item RAB), sort_order
budget_allocation_logs  project_id, user_id, action, before (JSON), after (JSON), created_at
```
- **Terbuka** bila proyek punya ≥ 1 invoice TERVERIFIKASI (selain itu tab
  menampilkan `Notice`: "Alokasi dibuka setelah pembayaran pertama
  diverifikasi Finance").
- Tab **Alokasi Dana**: kiri "Item belum dialokasikan" (item RAB Fix + item
  addendum disetujui), kanan daftar pos. PM: buat/ubah nama/urutkan/hapus
  pos kosong, pindahkan item ke pos (satu item = satu pos, `UNIQUE`).
- Ringkasan: Total RAB · **Diskon (pengurang)** · Pembulatan · Σ per pos ·
  belum dialokasikan. **Peringatan** (`Notice` warning) bila Σ pos > total
  RAB — tidak memblokir.
- Setiap perubahan → `budget_allocation_logs` (before/after); riwayat
  tampil di tab.
- RBAC: tulis = **PM proyek itu** saja (Asisten PM, Estimator tidak);
  baca = PM, CEO, Finance. **Marketing tidak** (props tidak dikirim).

## Checklist
- [x] **[Database]** Tiga tabel di atas
- [x] **[Projects]** `ProjectBudgetService`: syarat terbuka, CRUD pos, pindah item, ringkasan, peringatan, log
- [x] **[UI]** Tab Alokasi Dana (item ↔ pos, ringkasan, riwayat)
- [x] **[Test]** Terkunci sebelum verifikasi; PM lain/Asisten PM/Marketing 403; item tidak bisa di dua pos; log tercatat; diskon tidak masuk pos
