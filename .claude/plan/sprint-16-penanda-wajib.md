# Sprint 16 — Form: Penanda Wajib & Data Lead Konsisten

> Status: **selesai 2026-10-07** (Sub 01–08). Diminta user 2026-10-06: "tandai
> field yang bersifat required dengan bintang merah pada setiap form",
> lalu ditambah: "data lead konsisten — No. HP hanya angka diawali 0,
> email berformat email, kota dropdown".
>
> Aturan kerja tetap: `.claude/CLAUDE.md` + `.claude/rules/*`.
> - **Sub 01–06** tidak mengubah route, gate, aturan bisnis, atau
>   validasi server. Yang berubah hanya tampilan label, plus Zod di
>   frontend bila ternyata berbeda dari Form Request.
> - **Sub 07–08** sengaja **mengubah skema & validasi lead**: `contact`
>   dipecah menjadi `phone` + `email`, dan `city` menjadi `city_id`.

## 1. Permintaan
1. Setiap kolom yang wajib diisi diberi tanda **\*** merah di label-nya,
   di semua form sistem: dialog, halaman Create/Edit, form login/profil,
   dan form di link klien.
2. Data lead konsisten:
   - No. HP hanya berisi angka dan diawali 0.
   - Email harus berformat email.
   - Kota dipilih dari dropdown, bukan diketik bebas.

## 2. Kondisi sekarang (survei 2026-10-06)
- ±85 file form, ±330 label: `FormLabel` (RHF + Zod, 66 file / 295 label),
  `Label` shadcn biasa (11 file / 16 label), `InputLabel` bawaan Breeze
  (4 halaman Auth/Profile).
- Saat ini **tidak ada** penanda wajib. Yang ada justru kebalikannya: 47
  label bertuliskan "(opsional)", dan 1 label "(wajib jika reject)" (QA).
- Form Request sudah membedakan `required` / `nullable` dengan jelas, dan
  Zod mencerminkannya (`.min(1, '… wajib diisi')` lawan `.optional()`).
  Contoh: `StoreLeadRequest` ↔ `LeadFormDialog`.
- Lead:
  - `leads.contact` adalah teks bebas: isinya nomor dengan strip/`+62`,
    email, kadang handle Instagram. Karena itu ada dua salinan
    `whatsappNumber()` yang menebak apakah isinya nomor HP.
  - `leads.city` juga teks bebas, padahal Sumber & Kategori lead sudah
    berupa master (FK).

## 3. Keputusan (usulan — ubah di sini bila tidak setuju)
| # | Pertanyaan | Usulan |
|---|---|---|
| K1 | Cara menandai | Prop eksplisit **`<FormLabel required>`**, bukan dibaca otomatis dari skema Zod. Zod dengan `refine`/`superRefine`/`.or(z.literal(''))` dan aturan bersyarat tidak bisa dibaca dengan andal. |
| K2 | Patokan "wajib" | **Form Request** (server). `required` → bintang. `nullable`/`sometimes` → tanpa bintang. `required_if`/`required_with` → bintang **dinamis** (`required={decision === 'TOLAK'}`). Halaman Edit mengikuti Update Request-nya. |
| K3 | Warna & aksesibilitas | `text-error-ink`, bukan `text-destructive`: design-standards §1 mewajibkan token `-ink` untuk teks berwarna, karena `#ef4444` di atas putih terlalu pucat. Bintangnya `aria-hidden`, diganti `sr-only` "(wajib)" untuk pembaca layar. |
| K4 | Label "(opsional)" | **Dihapus** (47 label): dengan bintang, kolom tanpa bintang sudah berarti opsional, jadi dua penanda hanya dobel. "(wajib jika reject)" diganti bintang dinamis. |
| K5 | Keterangan "\* wajib diisi" di tiap form | **Tidak**: bintang merah sudah konvensi umum, dan keterangan tambahan memenuhi dialog kecil. |
| K6 | Checkbox / Switch | **Tanpa bintang**, karena "tidak dicentang" adalah nilai yang sah. Pengecualian: centang persetujuan yang servernya `accepted`. |
| K7 | Select ber-default (mis. Prioritas) | **Tetap diberi bintang** bila server `required`, supaya aturannya konsisten walaupun kolom itu tidak pernah kosong. |
| K8 | Kolom tabel isian (baris RAB, item pengajuan) | Bintang di **header kolom** (`<th>`), bukan di tiap sel. |
| K9 | Zod ≠ Form Request | Zod **disamakan ke server** (frontend-standards §3). Aturan server **tidak** diubah tanpa bertanya; perbedaannya dicatat di "Catatan pelaksanaan" sub terkait. |
| K10 | Bentuk kontak lead | **Dua isian, `phone` dan `email`, minimal satu terisi.** Lebih baik daripada pilihan jenis + satu isian, karena klien sering punya keduanya dan link WhatsApp butuh nomor HP. Alternatif bila tetap ingin pilihan: `contact_type` (HP/EMAIL) + satu isian. |
| K11 | Format No. HP | Hanya angka, diawali **08**, 10–13 digit. `+62`, `62`, spasi, dan strip diubah otomatis. Disimpan `081234567890`, ditampilkan `0812-3456-7890`. Telepon kantor (0761…) tidak diterima karena isiannya "No. HP" untuk WhatsApp. |
| K12 | Format email | Format email valid untuk **semua domain** (Gmail hanyalah salah satunya), disimpan dalam huruf kecil. |
| K13 | Kontak lama | Dikonversi oleh migrasi. Yang tidak bisa dikonversi (handle IG, nomor rusak) dipindah ke Catatan dengan awalan "Kontak lama: …", sehingga tidak ada data yang hilang. |
| K14 | Sumber daftar kota | **Master `cities`** di Data Master (SUPERADMIN), diisi 12 kab/kota Riau + kota besar sekitar, lalu dipilih lewat `CitySelect`. Marketing tidak bisa mengetik kota baru. Alternatif: seluruh ±514 kab/kota Indonesia + combobox pencarian. |
| K15 | Kota ↔ survey | Centang "Luar Pekanbaru" saat menjadwalkan survey otomatis terisi dari kota lead, dan masih bisa diubah. |

## 4. Rancangan teknis (rinci di Sub 01)
- `Components/shared/RequiredMark.tsx` berisi satu komponen bintang
  (`aria-hidden` + `sr-only`).
- `FormLabel` (`ui/form.tsx` ditulis manual, jadi boleh diubah) mendapat
  prop `required?: boolean`, yang merender `RequiredMark` setelah teks.
- `InputLabel` (Breeze, custom) mendapat prop yang sama.
- `Label` shadcn (`ui/label.tsx`) **tidak diubah**, karena primitive CLI
  akan tertimpa saat `shadcn add --overwrite`. Untuk 16 pemakaian `Label`
  biasa, `<RequiredMark />` ditulis langsung di dalamnya.

## 5. Sub-plan
| # | Sub-plan | Isi | File |
|---|---|---|---|
| 01 | [Fondasi & pilot](sprint-16/01-fondasi-pilot.md) | `RequiredMark`, `FormLabel required`, `InputLabel required`, dokumentasi standar, pilot `LeadFormDialog` | 4 + 1 pilot |
| 02 | [CRM, Desain, RAB, Auth](sprint-16/02-crm-desain-rab-auth.md) | Lead, survey, desain, quotation/RAB, link klien, login/profil/user | 21 |
| 03 | [Proyek, Tugas, Lapangan](sprint-16/03-proyek-tugas-lapangan.md) | Proyek, milestone, task, material proyek, anggaran, Daily Form, Lembur, QA | 16 |
| 04 | [Keuangan](sprint-16/04-keuangan.md) | Transaksi, pembayaran, invoice, pinjaman, hutang supplier, target omzet | 15 |
| 05 | [Logistik, Data Master, Pengaturan](sprint-16/05-logistik-master-pengaturan.md) | Material, aset, stok, pengajuan, master, vendor, Pengaturan Situs | 15 |
| 06 | [SDM & audit akhir](sprint-16/06-sdm-audit-akhir.md) | Karyawan, SP, KPI, evaluasi, gaji, struktur; audit seluruh form | 13 + audit |
| 07 | [Kontak lead: No. HP & Email](sprint-16/07-kontak-lead.md) | `contact` → `phone` + `email` berformat, helper `Phone`, konversi data lama | skema + 10 file |
| 08 | [Kota lead dari dropdown](sprint-16/08-kota-dropdown.md) | Master `cities` + `CitySelect` + `leads.city_id`, default survey luar kota | skema + 9 file |

Sub 07 dan 08 hanya butuh Sub 01, jadi boleh dikerjakan lebih dulu dari
Sub 02–06 ("Kerjakan Sprint 16 Sub 7"). Bila sudah selesai saat audit Sub
06, cek silangnya mencakup aturan lead yang baru.

Definisi selesai per sub: semua file di checklist sudah diberi bintang
sesuai K2–K8, `npm run build` lulus, minimal 2 form dicek di browser
(desktop + lebar HP 390px), checklist dicentang, dan perbedaan Zod/server
dicatat.

## 6. Rekap perbedaan Zod ↔ server (Sub 02–06, 2026-10-07)
| Form | Zod | Server | Tindak lanjut |
|---|---|---|---|
| Lembur — catatan tolak (`Overtime/Index`) | wajib saat Tolak | `OvertimeDecisionRequest` `note` `nullable` | Bintang mengikuti form. **Usul:** server `required_if:decision,reject` — menunggu persetujuan user (K9: server tidak diubah tanpa bertanya). |

Selain itu tidak ditemukan perbedaan.
