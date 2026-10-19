# Sprint 26 — UX Detail Proyek: Ikuti Alur, Bukan Fitur

> Status: **Sub 01–06 kode selesai 2026-10-19**, Sub 07–08 belum. Q1–Q6 dijawab
> user 2026-10-19 (semua = usulan; Q1 dengan catatan "saya mau lihat dulu" →
> titik cek setelah Sub 05, lihat §5).
> Sumber: pertanyaan user 2026-10-19 ("saran UX halaman proyek agar familiar,
> mudah dimengerti dan sesuai dengan alur") → 8 saran Claude, user: "tulis
> untuk semua nomor". Di luar PRD. Lanjutan Sprint 13 (navigasi), Sprint 23
> K3/K4b (menunggu siapa, aksi di header), Sprint 24 (fase) dan Sprint 25
> (Activity Target).
>
> **Tidak ada route, gate, Policy, atau aturan bisnis yang berubah** (sama
> dengan Sprint 13). Yang berubah hanya cara halaman `projects.show`
> ([`Pages/Projects/Show.tsx`](../../resources/js/Pages/Projects/Show.tsx))
> menyusun dan menamai isinya. Prop yang tidak boleh diterima suatu role
> tetap tidak dikirim (filter di controller tidak disentuh).

## 1. Permintaan

Detail Proyek harus terasa seperti alur yang sudah dirancang
(GAMBAR_KERJA → TM → PERSIAPAN → PRODUKSI → PEMASANGAN → Selesai), bukan
tumpukan fitur dari sprint ke sprint. Polanya harus familiar: lacak pesanan
(marketplace / ojek online) untuk fase, dan halaman proyek ala aplikasi kerja
(Ringkasan · Tugas · Timeline · Berkas) untuk tabnya.

## 2. Kondisi sekarang (2026-10-19)

| # | Temuan | Di kode |
|---|---|---|
| T1 | **11 tab level halaman**: Overview · Eksekusi · Timeline · QA/QC · Task · Lembur · Activity Target · Finance · Alokasi Dana · Dokumen · Material. Urutan tidak mengikuti alur; di HP harus digeser ke samping. | `Show.tsx` `UnderlineTabsList` |
| T2 | **Progres di 3 tempat**: kartu "Progress (Actual Activity)" di Overview, kartu "Actual Activity" di Eksekusi (`ProgressCard`, berisi tombol **Setujui Berangkat ke Lokasi**), tab Activity Target. Angkanya sama. | `OverviewTab`, `ProjectExecutionTab::ProgressCard`, `ActivityTargetTab` |
| T3 | **Dua "alokasi" beda isi**: "Alokasi Anggaran" di tab Finance = persen otomatis dari nilai kontrak (PRD §4.7, `AllocationCard`); tab "Alokasi Dana" = anggaran PM (`BudgetAllocationTab`). | `Show.tsx` `AllocationCard`, `BudgetAllocationTab` |
| T4 | **Istilah campur**: tombol "Tambah Daily Target", tapi "Belum ada task.", "Ubah Status Task", "Task selesai {nama}". Bahasa campur: Overview, Task, Finance. | `TaskTab`, `TaskAssigneeTable`, `PROJECT_TAB_LABEL` |
| T5 | **Fase hanya terlihat di tab Eksekusi** (kartu "Tahap Eksekusi"), padahal Marketing & Desainer tidak menerima tab itu (`ProjectExecutionService::canView`). Di header hanya chip fase + kalimat `step` yang tidak bisa diklik. | `ProjectExecutionTab`, `projectStep()` |
| T6 | **Tab Eksekusi menumpuk semua kartu semua fase** (Gambar Kerja, TM, Jasa Pengawas, Target vs Actual, Laporan Mingguan, Temuan) — Pengawas di fase PRODUKSI harus menggulir melewati kartu TM. | `ProjectExecutionTab` |
| T7 | **"Menunggu di Proyek Ini"** tidak memuat langkah fase (gambar kerja, TM, DP pengawas) dan tidak membedakan "giliran saya" dari "giliran orang lain". | `Show.tsx` `waiting` |
| T8 | **`confirm()` bawaan browser** (melanggar design-standards §2 — `ConfirmDialog`). | `Show.tsx:311`, `:316`, `:702`; `ProjectMaterialsPanel.tsx:212` |
| T9 | Tab **Finance** selalu tampil, lalu berisi "Anda tidak punya akses…" untuk role tanpa termin (Desainer, Estimator, Logistik, QA). | `useQueryTab({ finance: true })` |

## 3. Keputusan (usulan Claude, dikunci setelah §5 dijawab)

Nomor H1–H8 = nomor saran di percakapan.

### H1 — Fase selalu terlihat (pola lacak pesanan)

| # | Keputusan |
|---|---|
| H1a | Komponen baru **`ProjectPhaseStepper`** (`Components/modules/projects/`) tepat di bawah `PageHeader`, di atas `ProjectStatusNotice`: 5 fase + **Selesai** (6 titik). Sudah lewat = ✓ `success`, berjalan = fill `daiku-yellow-light` + garis kuning, belum = pudar. Di bawahnya satu baris "**Sekarang:** {fase} · giliran {pemegang}" dari `waitingOn('projectPhase', …)` (peta yang sama dengan chip). |
| H1b | Data dari **`project.phase` + `project.status`**, bukan `execution.phases` — supaya Marketing/Desainer (tanpa tab Eksekusi) juga melihatnya. Label fase dari peta yang sama dengan `StatusChip domain="projectPhase"`. Tanggal per fase hanya ditampilkan bila `execution` ada (`workingDrawing.completed_at`, `meeting.completed_at`, `departure.approved_at`). |
| H1c | Status lain: **ON_HOLD** → stepper tetap, titik berjalan diberi ikon jeda + "Ditahan"; **COMPLETED** → semua ✓; **CANCELLED** → stepper disembunyikan (Notice yang ada sudah cukup); proyek lama (`execution.isLegacy`) → 1–3 tampil "dilewati" (abu, tanpa ✓). |
| H1d | Kartu "Tahap Eksekusi" di tab Eksekusi **dihapus** (pindah ke stepper). Chip fase di header dihapus (stepper sudah menyebutnya); chip status proyek tetap. |
| H1e | **Kalimat `step` bisa diklik.** `projectStep()` mengembalikan `{ text, tab?, anchor? }`; `PageHeader step` (sudah `ReactNode`) menerima tombol-tautan bergaris bawah kuning → `setTab(tab)` lalu scroll ke `#anchor` (mis. TM → `execution` `#tm`, timeline ditolak → `milestone`). Tanpa tujuan = teks biasa. Teks kalimatnya tidak berubah. |
| H1f | HP (< `md`): stepper jadi satu baris ringkas "Tahap 3 dari 6 · Persiapan" + bar 6 segmen; ketuk → daftar 6 fase (`ResponsiveDialogContent`). Tidak ada geser horizontal. |

### H2 — Tab Eksekusi hanya fase yang berjalan

| # | Keputusan |
|---|---|
| H2a | Peta fase → kartu (satu tempat, `PHASE_CARDS` di `ProjectExecutionTab`): **GAMBAR_KERJA** → `WorkingDrawingCard` · **TM** → `TechnicalMeetingCard` · **PERSIAPAN** → `SupervisorFeeCard` (syarat & Ajukan DP) + rencana bahan (`TmMaterialPlanner`, revisi s.d. PRODUKSI tetap) · **PRODUKSI** → `TargetVsActualCard` + kartu **Berangkat ke Lokasi** (H4c) + `WeeklyReportsCard` + `SupervisorFeeCard` (pembayaran) · **PEMASANGAN** → `SiteFindingsCard` + `WeeklyReportsCard` + `SupervisorFeeCard`. |
| H2b | **Fase yang sudah lewat** dilipat di atas jadi satu baris per fase ("✓ Gambar Kerja selesai 12 Okt · {completer}", "✓ TM selesai 14 Okt · 6 peserta · 4 timeline"), klik → buka kartu lengkapnya hanya-baca (`Collapsible` shadcn — belum ada, `npx shadcn@latest add collapsible` + perbaiki casing import). Isi & tombol kartu tidak berubah — hanya kapan ditampilkan. |
| H2c | **Fase yang belum tiba** tidak dirender (tidak ada kartu kosong). Satu baris di bawah: "Berikutnya: {fase} — {apa yang terjadi}". |
| H2d | Kartu yang tetap berlaku lintas fase (laporan mingguan, temuan, pembayaran pengawas) muncul di setiap fase tempat ia berlaku (tabel H2a) — tidak ada kartu yang hilang dari fase yang masih memakainya. Peringatan H-30% (`execution.deadline.warning`) tetap di atas. |
| H2e | Proyek **COMPLETED/ON_HOLD**: semua kartu yang punya data tampil terlipat (riwayat), tanpa tombol aksi baru (aturan lama tetap). |
| H2f | Anchor yang dipakai tautan luar tetap ada: `#tm-bahan` (Perlu Tindakan Pengawas, `ActionInboxService::meetingsForSupervisor`) membuka kartu TM/PERSIAPAN yang memuat rencana bahan. |

### H3 — 6 tab mengikuti pekerjaan

| Tab baru (key) | Isi (tab kecil `TabsList` di dalamnya) | Dari tab lama | Tampil bila |
|---|---|---|---|
| **Ringkasan** (`overview`) | — (H6) | Overview | selalu |
| **Eksekusi** (`execution`) | — (H2) | Eksekusi | `execution` ≠ null |
| **Timeline & QC** (`milestone`) | Timeline (Gantt · Kalender) · Pemeriksaan QC | Timeline, QA/QC | selalu (sub QC bila `canViewQa`) |
| **Daily Target** (`task`) | Per Hari · Per Tukang · Board · Activity Target · Lembur | Task, Activity Target, Lembur | `canViewTasks` ∨ `activityTarget` ∨ `canViewOvertime` |
| **Material** (`material`) | — | Material | `canViewMaterials` |
| **Keuangan** (`finance`) | Termin & Invoice · Alokasi Dana · Hutang Supplier · Dokumen RAB | Finance, Alokasi Dana, Dokumen | salah satu sub tersedia |

| # | Keputusan |
|---|---|
| H3a | Urutan tab **tetap sama untuk semua role** dan mengikuti alur (Ringkasan → Eksekusi → Timeline → Daily Target → Material → Keuangan) — orang yang bicara satu sama lain melihat urutan yang sama. Yang berbeda per role hanya **tab awal** (H5). |
| H3b | Sub-tab di URL: **`?tab=…&view=…`**. Hook baru `useQueryView(tab, available, fallback)` (pola `useQueryTab`). Tanpa `view` → sub pertama yang tersedia. |
| H3c | **Key lama tetap berlaku** lewat alias di `useQueryTab` (`aliases: Record<string, [tab, view]>`): `qa → milestone/qc`, `progress → task/activity-target`, `overtime → task/lembur`, `budget → finance/alokasi`, `documents → finance/dokumen`. Tautan di `ActionInboxService`, `NotificationTarget`, `ProjectLink` (`ProjectTab`), Termin/Invoice/QA/Lembur/Pengajuan Barang/Task index tidak wajib diubah; diperbarui ke bentuk baru di Sub 05 supaya alamatnya rapi. |
| H3d | Sub-tab yang role-nya tidak boleh **tidak dirender** (bukan EmptyState "tidak punya akses"); tab yang semua sub-nya tidak boleh tidak tampil (memperbaiki T9). Prop tetap difilter di controller — tidak ada prop baru ke role baru. |
| H3e | Isi "Timeline & QC": tiap timeline di Gantt/Kalender/daftar mendapat chip QA (`StatusChip domain="qa"` dari `qaForms` per `milestone_id`); sub **Pemeriksaan QC** = `ProjectQaTab` sekarang. |
| H3f | Isi "Daily Target": sub Per Hari/Per Tukang/Board = `TaskTab` sekarang (sub kecilnya naik satu level); **Activity Target** = `ActivityTargetTab` (Ringkasan angka + tabel + Catatan Progress PM, keputusan Sprint 25 tetap); **Lembur** = `ProjectOvertimeTab`. QA non-pengawas hanya melihat sub Activity Target (angka saja — PRD §4.6 tetap). |
| H3g | Isi "Keuangan": **Termin & Invoice** = `FinanceTab` tanpa dua kartu ringkasan · **Alokasi Dana** = `BudgetAllocationTab` + `AllocationCard` sebagai kartu acuan di atasnya (H7) · **Hutang Supplier** = `SupplierDebtCard` · **Dokumen RAB** = `DocumentsTab`. Visibilitas tiap sub = prop yang sama dengan sekarang (`canViewTermins`, `budget`, `canViewFinanceSummary`, `documents`). |
| H3h | Breadcrumb: `{proyek} › {tab}` dan, bila sub bukan yang pertama, `› {sub}` (design-standards §4 — tab level halaman dikirim sendiri sebagai crumb terakhir). |

### H4 — Progres di satu tempat per kebutuhan

| # | Keputusan |
|---|---|
| H4a | **Ringkasan**: kartu Progress menampilkan actual % (besar) + "Target s.d. hari ini {target_to_date}%" + "Selisih {gap} poin" (`success-ink`/`error-ink`) dari `activityTarget.summary`, tautan "Lihat Activity Target →" (`task/activity-target`). Tanpa `activityTarget` → hanya % (seperti sekarang). |
| H4b | **Eksekusi**: kartu "Actual Activity" (`ProgressCard`) **dihapus sebagai kartu progres**; `TargetVsActualCard` (alat kerja Pengawas per tukang per minggu, keputusan Sprint 25 A8) tetap. |
| H4c | Tombol **Setujui Berangkat ke Lokasi** pindah ke kartu baru kecil **"Berangkat ke Lokasi"** di fase PRODUKSI: syarat "Actual activity ≥ {threshold}% — sekarang {progress}%" (`ProgressBar`, ✓ bila tercapai) + tombol (terkunci < threshold, aturan D11 tetap). Setelah disetujui → baris riwayat di fase PEMASANGAN. Tombol juga muncul di header (`PageHeader actions`) saat `can.approveDeparture` dan ≥ threshold — seperti TM Selesai (U6). |
| H4d | Sumber angka tetap satu: `Project::actualProgress()` / `ProductionService` — tidak ada perhitungan baru di frontend. |

### H5 — Tab awal menurut role

| # | Keputusan |
|---|---|
| H5a | Tanpa `?tab=`, halaman dibuka di tab awal role (fungsi `defaultProjectTab()` di `Show.tsx`, satu tempat): **Pengawas proyek ini** (`supervisor_id` = saya) → Eksekusi · **Finance** → Keuangan · **Marketing** → Keuangan (Termin & Invoice) · **Logistik** → Material · **Desainer / Kepala Desain** → Eksekusi bila fase GAMBAR_KERJA dan tab itu ada, selain itu Ringkasan · **PM / Asisten PM / CEO / lainnya** → Ringkasan. Role bertumpuk: urutan di atas, yang pertama cocok menang. |
| H5b | Tab awal yang tidak tersedia → Ringkasan. `?tab=` dari tautan selalu menang. `useQueryTab` menerima `fallback` per role (tab awal tidak ditulis ke URL, seperti sekarang). |
| H5c | Tidak ada pengaturan per user (Sprint 13 `nav_preferences` tidak dipakai di sini) — lihat Q3. |

### H6 — Ringkasan: di mana kita, giliran siapa, apa yang harus saya lakukan

| # | Keputusan |
|---|---|
| H6a | Susunan (atas → bawah): **Giliran Anda** (lebar penuh, hanya bila ada) → kiri 2/3: **Menunggu orang lain** + **Informasi Proyek** · kanan 1/3: **Progress** (H4a) + **Tim Proyek**. HP: satu kolom, urutan yang sama. |
| H6b | Baris "menunggu" (`waiting` sekarang) ditambah **langkah fase**: Gambar Kerja belum selesai (Kepala Desain / Arsitek), TM belum selesai (PM), syarat DP belum lengkap / DP belum diajukan (Pengawas), DP diajukan (PM), Berangkat ke Lokasi siap (PM, ≥ threshold). Tiap baris punya `holder` (teks, dari `lib/waitingOn.ts`) dan `mine` (boolean). |
| H6c | **`mine` dihitung di frontend dari prop yang sudah ada** — tidak ada query baru (golden rule 12 tetap: antrean lintas proyek tetap hanya di `ActionInboxService`): pakai flag `execution.can.*` (`completeMeeting`, `requestDp`, `decide`, `approveDeparture`, `submitWeekly`, `resolveFinding`…), `canManageMilestones`, `materialPermissions`, `canIssueTerminInvoices`, `canOpenQaForm` + `project.supervisor_id === saya` untuk QA. Baris tanpa flag yang jelas → "Menunggu orang lain". |
| H6d | **Giliran Anda** = `SectionCard` dengan baris `InboxGroupCard`-style: label + kalimat + **tombol aksi langsung** bila aksinya satu klik dan sudah punya komponen (TM Selesai → `CompleteMeetingButton`, Setujui Berangkat → H4c, Ajukan DP → buka kartu fee); selain itu tombol "Buka" → tab/anchor. Tidak ada aksi baru. |
| H6e | **Menunggu orang lain**: baris yang sama dengan sekarang, lebih redup (`text-muted-foreground`), dengan "· {holder}". Kosong → `EmptyState` "Tidak ada yang menunggu." |
| H6f | **Tim Proyek**: baris avatar inisial + nama + peran (PM, Asisten PM, Kepala Tim — "luar perusahaan" bila nama bebas, Pengawas (QA/QC), Arsitek gambar kerja bila `execution.workingDrawing`). Dipindah dari `DetailList` Informasi Proyek. |
| H6g | **Informasi Proyek** tinggal: Klien, Nilai Kontrak (hanya bila sekarang sudah dikirim), dan satu baris tanggal **"Mulai 1 Okt → Selesai 30 Nov · sisa 42 hari"** (lewat → `error-ink` "lewat 3 hari"; tanpa tanggal selesai → "belum ditentukan"). Tanggal Buka Proyek jadi teks kecil di bawahnya. |

### H7 — Istilah & bahasa

| Sekarang | Jadi | Di mana |
|---|---|---|
| Overview | **Ringkasan** | tab, crumb |
| Task (tab) | **Daily Target** | tab, crumb, `TaskTab` |
| "Belum ada task." | "Belum ada daily target — dibuat Pengawas setelah TM." (sama dengan Sprint 25 A10) | `TaskTab` |
| "Ubah Status Task" | (diganti chip yang bisa diklik — H8b) | `TaskAssigneeTable` |
| "Task selesai {nama}" / "n/m selesai" | "Daily target selesai {nama}" / tetap "n/m selesai" | `TaskAssigneeTable` |
| Finance | **Keuangan** | tab, crumb |
| QA/QC (tab) | **Timeline & QC** (sub "Pemeriksaan QC") | tab |
| "Alokasi Anggaran" (kartu di Finance) | **"Pos Anggaran Standar"** + deskripsi "Persentase baku dari nilai kontrak — acuan untuk Alokasi Dana." | `AllocationCard` |
| "Progress (Actual Activity)" | **"Progress"** (penjelasan "dari daily target yang selesai" sebagai deskripsi) | kartu Ringkasan |

Istilah papan tulis user tetap: **Daily Target, Actual Activity, Activity
Target** — sub-tab bernama **"Activity Target"** di layar lebar **dan HP**
(Q2; tidak disingkat — `TabsList` kecil boleh digeser bila sempit). Kode/tabel/
route tidak diganti (`task`, `milestone`, key tab).

### H8 — Perbaikan cepat

| # | Keputusan |
|---|---|
| H8a | 4 `confirm()` → **`ConfirmDialog`**: hapus timeline (`tone="danger"`), tandai timeline selesai ("Pengawas (QA/QC) proyek akan diminta memeriksanya."), tandai termin dibayar, hapus kebutuhan material (`tone="danger"`). Kalimat sama dengan sekarang. |
| H8b | Tabel **Per Tukang**: tombol "Ubah Status Task" diganti **`StatusChip` yang bisa diklik** (button, `aria-label="Ubah status {judul}"`, ikon chevron kecil) → `TaskStatusDialog` yang sama; hanya bila `canManage`, selain itu chip biasa. Kolom aksi tinggal `TaskRowMenu`. |
| H8c | `TaskAssigneeTable` diberi `mobileCard`-pattern `TableCard` (`<ul className="md:hidden">` + `<table className="hidden md:table">`) — saat ini tabel 6 kolom tanpa tampilan HP. |

## 4. Di luar cakupan

- Mengubah aturan fase, syarat TM/DP, 70%, penalti, bergulir, akses role.
- Antrean "Perlu Tindakan" lintas proyek (tetap `ActionInboxService`); Ringkasan hanya membaca prop halaman ini.
- Halaman daftar proyek (`Projects/Index.tsx`) dan Dashboard Proyek — bisa jadi sprint lanjutan (stepper mini per baris).
- Grafik kurva S (Sprint 25 Q3 = tidak).
- Mengubah urutan tab per role (H3a) atau menyimpan tab favorit per user (Q3).

## 5. Pertanyaan untuk user

Dijawab user 2026-10-19 — semua sesuai usulan.

| # | Pertanyaan | Jawaban |
|---|---|---|
| Q1 | Gabung **Task + Activity Target + Lembur** jadi satu tab "Daily Target" (Activity Target jadi sub-tab), padahal Activity Target baru dijadikan tab sendiri di Sprint 25? | **Ya, gabung — "saya mau lihat dulu".** Dikerjakan seperti H3f, lalu **titik cek user setelah Sub 05** (di browser) sebelum Sub 06. Bila tidak cocok: Activity Target kembali jadi tab sendiri tepat setelah Daily Target (7 tab) — cukup ubah peta tab + alias `progress`, isi tab tidak berubah |
| Q2 | Nama sub-tab rekap: "Rekap" atau "Activity Target"? | **"Activity Target"**, juga di HP (tidak disingkat) |
| Q3 | Tab awal per role (H5) cukup aturan tetap, atau user boleh memilih tab awalnya sendiri? | **Aturan tetap** (H5) |
| Q4 | Fase yang sudah lewat di Eksekusi: dilipat (bisa dibuka) atau disembunyikan sama sekali? | **Dilipat**, bisa dibuka (H2b) |
| Q5 | Marketing & Desainer melihat stepper fase di Ringkasan (tanpa tab Eksekusi)? | **Boleh** (H1b) — hanya label & pemegang giliran, tanpa data eksekusi |
| Q6 | Tombol aksi langsung di "Giliran Anda" (H6d), atau cukup tombol "Buka" ke kartunya? | **Ya, aksi langsung** untuk yang sudah punya komponen satu klik (TM Selesai, Setujui Berangkat), "Buka" untuk sisanya (H6d) |

## 6. Sub-plan

Satu perintah = satu sub ("Kerjakan Sprint 26 Sub 1"). Urutan dari risiko
terendah; tiap sub berdiri sendiri dan lulus build.

### Sub 01 — Perbaikan cepat (H8) — **kode selesai 2026-10-19**
- [x] `ConfirmDialog` untuk hapus/tandai selesai timeline (`MilestoneTab`), tandai termin dibayar (`FinanceTab`), hapus kebutuhan material (`ProjectMaterialsPanel`)
- [x] Chip status yang bisa diklik di `TaskAssigneeTable` (H8b) + tampilan HP (H8c) — kolom aksi tinggal `TaskRowMenu`; label progress per tukang sudah "Daily target selesai" (H7, sisanya Sub 02)
- [x] `grep -rn "confirm(" resources/js/Pages/Projects resources/js/Components/modules/projects` kosong
- [x] `npm run build` bersih (frontend saja — tidak ada perubahan backend, Pest tidak terdampak)
- [ ] Cek di browser: 4 dialog + chip status (layar lebar & HP 390px)

### Sub 02 — Istilah & bahasa (H7) — **kode selesai 2026-10-19**
- [x] Label tab & crumb: Overview → **Ringkasan**, Task → **Daily Target**, Finance → **Keuangan** (`PROJECT_TAB_LABEL`, `TabsTrigger`). "QA/QC" → "Timeline & QC" ikut Sub 05 (saat tabnya digabung)
- [x] `TaskTab` empty state, kartu Progress Ringkasan ("Progress" + "Dihitung dari daily target yang selesai."), `AllocationCard` → "Pos Anggaran Standar" + "acuan untuk Alokasi Dana"
- [x] Dialog daily target bersama (dipakai Detail Proyek & daftar Task): `TaskFormDialog` (label, notice, pesan Zod), `TaskDeleteDialog`, `TaskRowMenu` ("Ubah/Hapus Daily Target"), `TaskStatusDialog` (judul netral "Ubah Status" — juga dibuka Tukang, yang memakai kata "Tugas")
- [x] Tidak ada test/teks server yang memeriksa label lama (Form Request task tanpa pesan judul khusus)
- [x] `simulasi-alur-klien.md` / `simulasi-eksekusi-proyek.md`: tab **Keuangan**, tab **Daily Target**
- [x] `npm run build` bersih
- Tidak diubah (di luar cakupan §4): label menu & halaman daftar **Task** (`Tasks/Index`, tombol "Ubah Status Task" di sana)

### Sub 03 — Stepper fase + langkah yang bisa diklik (H1) — **kode selesai 2026-10-19**
- [x] `Components/modules/projects/ProjectPhaseStepper.tsx` (H1a–c, H1f), label dari `domainStatusLabel('projectPhase')`, pemegang giliran dari `waitingOn('projectPhase')`. **Beda dari H1a:** "giliran {pemegang}" ditulis di bawah label fase yang berjalan (bukan baris terpisah), karena kalimat `step` di header sudah menjelaskan langkahnya. Tanggal selesai per fase: gambar kerja `completed_at`, TM `completed_at`, Persiapan = keputusan DP PM, Produksi = Berangkat ke Lokasi
- [x] Dipasang di bawah `PageHeader`; chip fase di header dan kartu "Tahap Eksekusi" dihapus (H1d); catatan proyek lama tetap satu baris di atas tab Eksekusi
- [x] `projectStep()` → `{ text, tab?, anchor? }`; kalimat jadi tautan bergaris bawah kuning bila tab tujuan tersedia untuk viewer (`openTabAt`: ganti tab → scroll ke kartu). Anchor di tab Eksekusi: `#gambar-kerja`, `#tm`, `#dp`, `#berangkat`, `#laporan-mingguan`, `#temuan` (`#tm-bahan` tetap), wrapper `empty:hidden` untuk kartu yang tidak merender apa-apa
- [x] `npm run build` bersih (frontend saja)
- [ ] Cek di browser: ACTIVE tiap fase, ON_HOLD, COMPLETED, CANCELLED, proyek lama; Marketing melihat stepper tanpa tab Eksekusi; HP 390px (baris ringkas → panel bawah)
- Catatan: viewer tanpa data Eksekusi (Marketing, Desainer) tidak tahu `isLegacy` — di proyek lama, fase 1–3 tampil ✓ selesai (tanpa tanggal), bukan "dilewati"

### Sub 04 — Eksekusi per fase (H2, H4b–c) — **kode selesai 2026-10-19** (subagent)
- [x] `PHASE_CARDS` di `ProjectExecutionTab`: GAMBAR_KERJA → Gambar Kerja · TM → TM · PERSIAPAN → Jasa Pengawas + **Rencana Bahan** + Target vs Actual · PRODUKSI → Target vs Actual + **Berangkat ke Lokasi** + Laporan Mingguan + Jasa Pengawas · PEMASANGAN → Temuan + Laporan Mingguan + Jasa Pengawas. Kartu lintas fase dirender sekali, di fase terakhir yang dicapai
- [x] Fase lewat terlipat di kartu "Tahap Sebelumnya" (satu baris per fase, klik → kartunya; `Components/ui/collapsible.tsx` lewat CLI) + "Berikutnya: …" (H2b–c). Baris terbuka sendiri bila kartunya menunggu viewer (pembayaran/pelunasan, laporan mingguan, temuan terbuka). Proyek lama → satu catatan, bukan tiga baris "dilewati"
- [x] Kartu **Berangkat ke Lokasi** (`DepartureButton`, dipakai juga di header & Ringkasan) menggantikan `ProgressCard` (H4b–c); tombol header muncul saat ≥ threshold
- [x] Hash: `#…` menuju kartu di fase terlipat membuka barisnya & scroll (juga `hashchange`), sekali per URL
- [x] COMPLETED/ON_HOLD/CANCELLED: kartu "Riwayat Eksekusi", semua fase terlipat (H2e)
- [x] Tidak ada perubahan backend; test Pest alur eksekusi tetap lulus (suite penuh)
- **Beda dari H2a/H2f:** di PERSIAPAN rencana bahan hanya **kartu tautan** ke tab Material — server (`ProjectExecutionService`) hanya mengirim data & izin planner selama TM. Menampilkan planner di PERSIAPAN butuh backend kirim `meetingMaterials` + `planMaterials/updateMaterials/removeMaterials` di fase itu (usulan lanjutan). `#tm-bahan` dari Perlu Tindakan hanya muncul selama TM, jadi tetap mendarat di kartu TM
- [ ] Cek di browser per fase + HP

### Sub 05 — 6 tab + sub-tab + alias (H3) — **kode selesai 2026-10-19**
- [x] `useQueryTab(available, fallback, aliases)` → `[tab, setTab(tab, view?), view, setView]`; `resolveView()`; `?view=` dihapus saat pindah tab (H3b–c). Halaman lain yang memakai `useQueryTab` tidak berubah
- [x] `Show.tsx`: **Ringkasan · Eksekusi · Timeline & QC** (Gantt · Kalender · Pemeriksaan QC; HP: Timeline · Pemeriksaan QC) **· Daily Target** (Per Hari · Per Tukang · Board · Activity Target · Lembur) **· Material · Keuangan** (Termin & Invoice · Anggaran · Hutang Supplier · Dokumen). Komponen `ViewTabs` (bar hanya bila > 1 view). Sub tanpa akses tidak dirender, tab tanpa sub disembunyikan (T9 — Desainer/Estimator/Logistik/QA tidak lagi melihat Keuangan kosong)
- [x] Chip **"Perlu Perbaikan"** (QC terakhir ditolak) per timeline di Gantt, Kalender, daftar HP (H3e)
- [x] Breadcrumb `proyek › tab › view` (view bukan yang pertama) (H3h); label tab "Timeline & QC" hanya untuk yang menerima QC
- [x] Alias `qa → milestone/qc`, `progress → task/activity-target`, `overtime → task/lembur`, `budget → finance/alokasi`, `documents → finance/dokumen`; `ProjectLink` (`ProjectTab` 6 key + `view`), `ActionInboxService` (alokasi, qc, termin), `Finance/Invoices`, `Overtime`, `QA` index diperbarui; `ActionInboxServiceTest` → `tab=milestone&view=qc`
- **Beda dari H3g:** sub "Alokasi Dana" bernama **"Anggaran"** — berisi Pos Anggaran Standar (CEO/PM/**Asisten PM**/Finance) + Alokasi Dana (CEO/PM/Finance saja — Asisten PM tidak pernah menerima `budget`, golden rule 11); dengan satu nama yang sama untuk semua role
- [x] **Titik cek Q1** — user 2026-10-19: "saya cocok dengan UI/UX sekarang" → tab Daily Target gabungan dipertahankan; penyimpangan Sub 04/05 (rencana bahan di Persiapan = kartu tautan, sub "Anggaran", Target vs Actual sejak Persiapan) **diterima apa adanya**
- [ ] Cek tiap role di browser

### Sub 06 — Ringkasan baru (H4a, H6) — **kode selesai 2026-10-19** (subagent)
- [x] `projectWaiting.ts` (fungsi murni): baris menunggu lama dipecah per pemegang + baris fase (Gambar Kerja, TM, DP diajukan / syarat DP / DP belum diajukan, Berangkat siap, laporan mingguan jatuh tempo); `holder` + `mine` hanya dari prop halaman (`execution.can.*`, izin, `pm_id`/`supervisor_id`/`assistant_pm_id`) — tanpa query baru
- [x] `ProjectOverviewTab.tsx`: **Giliran Anda** (aksi langsung TM Selesai / Setujui Berangkat, selain itu "Buka"), **Menunggu orang lain**, **Informasi Proyek** (Klien, Nilai Kontrak, "Mulai → Selesai · sisa n hari"), **Progress** (actual + target s.d. hari ini + selisih, "Lihat Activity Target →"), **Tim Proyek**; `OverviewTab`/`waiting`/`terminTrigger` lama dihapus dari `Show.tsx`
- Catatan: Gambar Kerja tidak pernah "mine" (halaman hanya tahu nama PIC); QC tertolak tidak "mine" untuk CEO; baris "Finance" memakai `canMarkTerminPaid` (= FINANCE/SUPERADMIN)
- [ ] HP 390px & cek per role di browser

### Sub 07 — Tab awal per role (H5)
- [ ] `defaultProjectTab(roles, project, userId, available)` + `fallback` per role di `useQueryTab`
- [ ] Cek: Pengawas → Eksekusi, Finance/Marketing → Keuangan, Logistik → Material, Desainer (fase GAMBAR_KERJA) → Eksekusi bila tersedia, lainnya → Ringkasan; `?tab=` tetap menang

### Sub 08 — Uji & dokumen
- [ ] Suite Pest penuh, Pint, `npm run build`
- [ ] Uji di browser per role (proyek demo fase TM, PERSIAPAN, PRODUKSI, PEMASANGAN, COMPLETED) + HP 390px
- [ ] `CLAUDE.md`: satu kalimat di aturan 17 (tab Detail Proyek = 6 tab + stepper fase; key lama sebagai alias); `design-standards.md` §2: `ProjectPhaseStepper` bila dipakai di tempat lain
- [ ] README: baris Sprint 26 → status
