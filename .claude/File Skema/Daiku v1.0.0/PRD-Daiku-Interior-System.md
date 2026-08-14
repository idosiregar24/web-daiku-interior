# Product Requirements Document (PRD)
## Daiku Interior — Enterprise Web Information System
**Version:** v1.0.0 — Initial Release
**Status:** Draft · Pre-Revision
**Prepared by:** Lead Software Architect
**Last Updated:** Agustus 2026

---

## Table of Contents
1. [Project Overview](#1-project-overview)
2. [Stakeholders & Users](#2-stakeholders--users)
3. [Tech Stack & Architecture](#3-tech-stack--architecture)
4. [System Modules & Features](#4-system-modules--features)
5. [Database Schema & Entities](#5-database-schema--entities)
6. [Business Logic & State Machines](#6-business-logic--state-machines)
7. [Role-Based Access Control (RBAC)](#7-role-based-access-control-rbac)
8. [UI/UX Design System](#8-uiux-design-system)
9. [Security Requirements](#9-security-requirements)
10. [Development Process & Roadmap](#10-development-process--roadmap)
11. [Infrastructure & Deployment](#11-infrastructure--deployment)
12. [Open Items (TBD)](#12-open-items-tbd)

---

## 1. Project Overview

### 1.1 Executive Summary
Daiku Interior Enterprise Web System adalah sistem informasi internal berbasis web yang mengintegrasikan seluruh divisi operasional perusahaan interior **Daiku Interior** dalam satu platform terpusat. Sistem ini mencakup alur bisnis end-to-end mulai dari manajemen prospek klien (presales/CRM) hingga eksekusi lapangan, keuangan, logistik, dan pelaporan eksekutif.

### 1.2 Tujuan Sistem
- Mendigitalisasi dan mengotomasi seluruh alur kerja antar divisi
- Meningkatkan visibilitas progres proyek secara real-time
- Mengurangi bottleneck komunikasi dan approval antar tim
- Menyediakan data analitik akurat untuk pengambilan keputusan level CEO
- Menegakkan accountability tim lapangan melalui task management dan sistem penalti

### 1.3 Scope
- **In Scope:** CRM/Pipeline, Desain, Quotation, Project Management, Task Management, QA Gatekeeping, Finance & Cash Flow, Logistik & Material, Notifikasi In-App, Analytics Dashboard, Export PDF/Excel
- **Out of Scope:** Mobile native app (fase pertama), integrasi ERP eksternal, multi-currency

### 1.4 Target Pengguna
- **Jumlah user aktif:** 50–100 orang
- **Lokasi:** 1 kantor pusat (dengan kemungkinan ekspansi cabang di masa mendatang)
- **Bahasa antarmuka:** Bahasa Indonesia

---

## 2. Stakeholders & Users

| Role | Divisi | Tanggung Jawab Utama |
|---|---|---|
| CEO | Eksekutif | Full access, analytics, pengambilan keputusan strategis |
| Marketing | Presales / CRM | Manajemen lead, follow-up klien, pipeline |
| Designer | Desain | Pembuatan konsep dan brief desain awal |
| Estimator | Estimasi | Penyusunan RAB/Quotation |
| Project Manager (PM) | Eksekusi | Manajemen proyek, milestone, task, approval |
| QA | Quality Assurance | Validasi setiap tahap sebelum proyek lanjut |
| Finance | Keuangan | Cash flow, termin, pengelolaan dana penalti |
| Logistics | Logistik | Material, stok, aset perusahaan |
| Field Staff / Tukang | Lapangan | Eksekusi task harian, input progress |

---

## 3. Tech Stack & Architecture

### 3.1 Frontend
| Komponen | Teknologi | Versi |
|---|---|---|
| Framework | React 18 + Inertia.js v2 | Latest |
| SSR / Routing | Laravel Inertia (server-driven, bukan SPA murni) | v2.x |
| UI Library | shadcn/ui + Tailwind CSS | Latest |
| Charts / Analytics | Recharts | Latest |
| Real-time Client | Laravel Echo + pusher-js | Latest |
| Form Handling | React Hook Form + Zod | Latest |
| Table | TanStack Table | Latest |
| Date Utilities | date-fns | Latest |
| Route Helper | Ziggy (Laravel route di React) | Latest |

### 3.2 Backend
| Komponen | Teknologi | Versi |
|---|---|---|
| Language | PHP | 8.3 |
| Framework | Laravel | 11.x |
| ORM | Eloquent ORM | (built-in Laravel) |
| Database | MySQL | 16.x |
| Cache | Redis (Laravel Cache driver) | 7.x |
| Job Queue | Laravel Queue + Redis driver | (built-in Laravel) |
| Queue Monitor | Laravel Horizon | Latest |
| Real-time Server | Laravel Echo + Soketi (self-hosted) | Latest |
| Auth & RBAC | Laravel Breeze + Spatie Laravel Permission | Latest |
| File/Link Storage | Link reference only (Google Drive/Figma URL) | — |
| Validation | Laravel Form Request | (built-in Laravel) |
| PDF Export | DomPDF via barryvdh/laravel-dompdf | Latest |
| Excel Export | Laravel Excel (Maatwebsite) | Latest |
| Debugging | Laravel Telescope (dev only) | Latest |

### 3.3 Infrastructure
| Komponen | Pilihan | Keterangan |
|---|---|---|
| Containerisasi | Docker + Docker Compose | Semua service dalam container |
| Process Manager | PM2 / Docker Swarm | Zero-downtime deploy |
| CI/CD | GitHub Actions | Auto deploy ke staging & production |
| Reverse Proxy | Nginx | SSL termination, load balancer |
| Monitoring | Grafana + Prometheus | Metrics & alerting |
| Backup DB | mysqldump cron harian | Otomatis setiap tengah malam |

### 3.4 Struktur Project (Single Laravel Repository)

> Tidak menggunakan monorepo — satu repo Laravel sudah mencakup backend dan frontend (Inertia). Lebih sederhana dan lebih cepat untuk tim kecil.

```
daiku-interior/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   ├── CRM/                  # LeadController, PipelineController
│   │   │   ├── Design/               # DesignController
│   │   │   ├── Quotation/            # QuotationController
│   │   │   ├── Projects/             # ProjectController, MilestoneController
│   │   │   ├── Tasks/                # TaskController, DailyFormController
│   │   │   ├── Overtime/             # OvertimeController
│   │   │   ├── QA/                   # QAFormController
│   │   │   ├── Finance/              # TerminController, TransactionController
│   │   │   ├── Logistics/            # MaterialController, AssetController
│   │   │   └── Analytics/            # AnalyticsController (CEO Dashboard)
│   │   ├── Middleware/
│   │   └── Requests/                 # Form Request validation per modul
│   ├── Models/                       # Eloquent models
│   ├── Services/                     # Business logic (PenaltyService, TerminService)
│   ├── Jobs/                         # Queue jobs (DailyPenaltyJob, TerminReminderJob)
│   ├── Events/                       # Domain events (OvertimeApproved, QACompleted)
│   ├── Listeners/                    # Event handlers (SendNotification, dll)
│   ├── Notifications/                # Laravel Notification classes (in-app)
│   └── Exports/                      # Laravel Excel export classes
│
├── resources/
│   ├── js/
│   │   ├── Pages/                    # Inertia React pages (1:1 dengan controller)
│   │   │   ├── Auth/
│   │   │   ├── CRM/
│   │   │   ├── Design/
│   │   │   ├── Quotation/
│   │   │   ├── Projects/
│   │   │   ├── Tasks/
│   │   │   ├── Overtime/
│   │   │   ├── QA/
│   │   │   ├── Finance/
│   │   │   ├── Logistics/
│   │   │   └── Executive/            # CEO Dashboard
│   │   ├── Components/
│   │   │   ├── ui/                   # shadcn base components
│   │   │   ├── shared/               # Shared (PageHeader, Table, Modal)
│   │   │   └── modules/              # Per-divisi components
│   │   ├── Layouts/
│   │   │   ├── AppLayout.tsx         # Sidebar + Topbar
│   │   │   └── AuthLayout.tsx
│   │   ├── types/
│   │   │   └── index.ts              # TypeScript interfaces
│   │   └── lib/
│   │       ├── utils.ts
│   │       └── echo.ts               # Laravel Echo setup
│   └── css/
│
├── database/
│   ├── migrations/
│   └── seeders/
│
├── routes/
│   └── web.php                       # Semua route (group middleware per modul)
│
└── config/
```

---

## 4. System Modules & Features

### 4.1 Modul CRM / Presales (Marketing)

#### Fitur Utama
- **Lead Management:** CRUD data calon klien (nama, kontak, sumber lead, kategori, layanan, kota, gender, detail order)
- **Priority Flag:** HOT / WARM / COLD per lead (sesuai operasional nyata tim marketing Daiku)
- **Pipeline Status Tracking:**
  - `FOLLOW_UP` → `DEAL_DESAIN` → `CLOSING` → `LOST`
- **Kategori Customer:** RESIDENTIAL, KOMERSIAL, DEVELOPER, KONTRAKTOR, LAINNYA
- **Layanan:** BUILD INTERIOR RUMAH / CAFE / KANTOR / TOKO, BUILD EXTERIOR, DESAIN INTERIOR, DESAIN EXTERIOR
- **Sumber Lead:** Instagram, TikTok, Referral, Walk-in, WhatsApp, Marketplace, Iklan Sosmed, Website
- **Follow-Up Scheduler:** Notifikasi otomatis untuk follow-up yang sudah jatuh tempo (highlight jika lewat tanggal)
- **Alasan Lost:** Wajib diisi saat status berubah ke LOST (untuk analisis pola)
- **Pipeline History Log:** Rekam jejak setiap perubahan status beserta siapa yang mengubah dan kapan
- **Konversi ke Desain:** Ketika status `DEAL_DESAIN`, sistem membuka modul Desain untuk lead tersebut

#### Business Rules
- Hanya Marketing/Sales dan CEO yang bisa membuat/edit lead
- Alasan lost WAJIB diisi saat status pindah ke LOST
- Lead LOST tidak bisa diubah kembali (buat lead baru jika klien kembali)
- Follow-up date yang sudah lewat otomatis highlight sebagai reminder

---

### 4.2 Modul Desain

#### Fitur Utama
- **Design Brief:** Form input brief dari klien (kebutuhan, referensi, jenis project, catatan)
- **PIC & Sub-Staff:** Setiap proyek desain punya PIC utama (NISA, YOLA, UMI, ROJAB, PRAMA) dan bisa melibatkan sub-staff (LIKA, YUNA, ALEX, BIANDRA, IKBAL) dengan role masing-masing
- **Jenis Project:** Toko, Cafe, Renovasi, Kamar Set, Kitchen Set, Kantor, Arsitektural, Ruang Tamu/TV, Retail/Toko
- **Target Hari & Deadline:** PM/PIC set target durasi, sistem hitung deadline otomatis dan delay jika lewat
- **Status Desain** (sesuai alur operasional nyata):
  `BRIEF` → `DESAIN` → `WAITING_ACC_DESAIN` → `REVISI_DESAIN` → `ACC_DESAIN` → `GAMBAR_RAB` → `PEMBUATAN_PENAWARAN` → `WAITING_ACC_PENAWARAN` → `PRODUKSI` → `DONE_PRODUKSI`
  *Cabang:* `REJECT_PRODUKSI` / `HOLD_CLIENT` / `REVISI_CLIENT`
- **Link Desain:** Input URL Google Drive / Figma (array, tidak ada upload file)
- **Problem/Kendala:** Field khusus mencatat hambatan per proyek
- **KPI per PIC:** Dashboard total project per desainer, on schedule vs delay
- **Tracking Omset Desain:** Omset dan piutang client per bulan per proyek desain
- **Client ACC:** Konfirmasi ACC desain → trigger ke tahap Gambar RAB → Penawaran

#### Business Rules
- PIC utama wajib di-assign saat proyek desain dibuat
- Status DELAY otomatis jika melewati deadline tanpa status DONE
- Sistem hitung `delay_hari` otomatis setiap hari
- Link desain bisa lebih dari satu (array JSON)
- REJECT_PRODUKSI artinya proyek kembali ke tahap desain ulang
- HOLD_CLIENT dan REVISI_CLIENT tidak menghitung delay (waktu ditangguhkan)

---

### 4.3 Modul Quotation / RAB

#### Fitur Utama
- **Pembuatan RAB:** Estimator membuat daftar item pekerjaan dengan qty, satuan, harga satuan
- **Total Kalkulasi Otomatis:** Sistem menghitung subtotal dan total secara otomatis
- **Status Quotation:** `DRAFT` → `SUBMITTED` → `CEO_REVIEW` → `PM_REVIEW` → `SENT_TO_CLIENT` → `APPROVED` / `REJECTED`
- **Dual Approval:** CEO dan PM harus approve sebelum quotation dikirim ke klien
- **Export PDF:** Generate dokumen penawaran dalam format PDF siap kirim ke klien
- **Validity Period:** Tanggal berlaku penawaran (default 14 hari dari tanggal kirim)
- **Versi Revisi:** Sistem menyimpan riwayat revisi quotation

#### Business Rules
- Quotation hanya bisa dibuat jika Design sudah `clientAcc = true`
- Approval harus berurutan: CEO approve dulu, baru PM
- Jika PM reject, kembali ke Estimator untuk revisi
- Konversi ke Project hanya bisa dilakukan setelah status `APPROVED` dan konfirmasi Deal dari Marketing

---

### 4.4 Modul Project Management

#### Fitur Utama
- **Project Overview:** Informasi proyek (nama, klien, PM, nilai kontrak, tanggal mulai/selesai)
- **Milestone & Timeline:** PM membuat fase proyek dengan target tanggal (contoh: 3D Design, Produksi, Instalasi, Finishing)
- **Status Milestone:** `PENDING` → `IN_PROGRESS` → `QA_WAITING` → `COMPLETED` / `OVERDUE`
- **Task Assignment:** PM membuat dan assign task per tukang per milestone
- **Task Lock:** Tukang tidak bisa edit isi task (judul, deskripsi, tanggal) — hanya bisa update status progress
- **Progress Log:** PM input log progres harian dengan persentase dan deskripsi
- **Overdue Monitor:** Dashboard khusus PM untuk memantau task yang melewati deadline
- **Termin Schedule:** PM mengatur jadwal dan persentase pembayaran termin per milestone

#### Business Rules
- Project hanya bisa dibuat dari Lead yang berstatus `DEAL`
- PM di-assign oleh CEO saat project dibuat
- Task tidak bisa dipindah milestone oleh siapapun kecuali PM
- Progress baru bisa di-input jika QA milestone sebelumnya sudah `APPROVED`
- Kalkulasi penalti berjalan otomatis setiap tengah malam via scheduled job

---

### 4.5 Modul Task Management (Field Staff / Tukang)

#### Fitur Utama
- **Task List:** Tukang melihat daftar task yang di-assign ke mereka (hari ini & minggu ini)
- **Update Status:** `PENDING` → `IN_PROGRESS` → `DONE`
- **Status Task** (sesuai operasional tim nyata):
  `PENDING` → `ONPROGRESS` → `PENGECEKAN` → `DONE` / `OVER`
  - PENGECEKAN = sedang direview/dicek oleh PIC/PM sebelum dinyatakan DONE
  - OVER = task melewati deadline (overdue)
- **Field Kendala & Note:** Tukang/desainer bisa isi kendala yang dihadapi dan catatan tambahan
- **Prioritas Task:** HIGH / MEDIUM / LOW
- **Daily Form Wajib:** Setiap hari kerja, tukang wajib mengisi form daily (status update + kendala + note)
- **Pengajuan Lembur:** Tukang ajukan lembur ke PM → PM approve → Finance approve → dicatat sebagai pengeluaran

#### Business Rules
- Form daily task wajib diisi sebelum jam 21:00 WIB setiap hari kerja (Senin–Sabtu)
- Jika tidak mengisi → **penalti otomatis Rp 50.000** masuk ke Dana Family Gathering
- Status OVER otomatis diset oleh sistem jika task belum DONE melewati due_date
- Task yang di-assign PM bersifat immutable bagi tukang (hanya status, kendala, note yang bisa diubah)
- Lembur hanya bisa diajukan untuk hari yang sudah berlalu atau berjalan

---

### 4.6 Modul QA (Quality Assurance)

#### Fitur Utama
- **QA Form per Milestone:** Setiap kali PM menandai milestone selesai, sistem otomatis membuat QA Form
- **Checklist Validasi:** Form berisi checklist item kualitas yang harus diverifikasi QA (isian item checklist dikonfigurasi per tipe milestone)
- **Status QA:** `PENDING` → `APPROVED` / `REJECTED`
- **Catatan Penolakan:** Jika QA reject, wajib mengisi catatan perbaikan yang diperlukan
- **Blocking Mechanism:** Milestone selanjutnya terkunci selama QA Form belum `APPROVED`
- **Notifikasi:** PM mendapat notifikasi langsung ketika QA approve/reject

#### Business Rules
- QA Form dibuat otomatis oleh sistem, bukan oleh PM atau QA
- QA tidak bisa melihat detail task tukang — hanya melihat ringkasan progres milestone
- Jika QA reject dua kali berturut-turut pada milestone yang sama, CEO mendapat notifikasi
- QA Form yang sudah `APPROVED` tidak bisa diubah kembali

---

### 4.7 Modul Finance / Keuangan

#### Fitur Utama
- **Multi-Rekening:** Sistem mengelola beberapa rekening bank perusahaan (BCA 5835, BCA 4342, Mandiri, BRI, BNI, Mandiri PT, dll) — setiap transaksi tercatat masuk/keluar dari rekening mana
- **Cash Flow Dashboard:** Ringkasan pemasukan dan pengeluaran per rekening dan keseluruhan
- **Kategori Transaksi Lengkap** (sesuai operasional nyata):
  Pemasukan: Down Payment, Termin, Pindah Dana
  Pengeluaran: Operasional, Beli Bahan, Angsuran, Gaji Karyawan, Lembur & Bonus, Logistik, Hutang Ideal, Pegangan, Jasa Desain, Vendor, Konsumsi, Consumable, Peralatan/Aset, BBM, Owner, Pinjaman
- **Alokasi Persentase Otomatis:** Sistem mengalokasikan persentase dari nilai proyek secara otomatis (Gaji 12%, Operasional 2%, Consumable 1%, Penyusutan 1%, Listrik 1%, Konsumsi 1%, Lembur 1%, Bonus 1%, Angsuran 1%)
- **Gaji Karyawan Tetap:** Pencatatan dan pembayaran gaji bulanan karyawan (Boy, Yola, Icha, Ami, Ibnu, Umi, Ilham, Hesti, Satria, dll)
- **Pinjaman Tukang (Staff Loans):** Pencatatan pinjaman per tukang + cicilan angsuran yang dipotong dari upah
- **Hutang Supplier:** Tracking hutang ke supplier (Hutang Ideal, Hutang Kaca, dll) + riwayat pembayaran
- **Aset & Cicilan:** Pencatatan aset perusahaan yang masih dalam cicilan (Mobil Pickup, Scross, Laptop, dll)
- **Termin Management:** Jadwal Sabtu, DP + pelunasan, sisa piutang otomatis terhitung
- **Dana Family Gathering:** Akumulasi penalti Rp50.000 per pelanggaran
- **Export Excel:** Laporan cash flow per bulan, per proyek, per rekening

#### Business Rules
- Setiap transaksi wajib mencantumkan rekening bank (bank_account_id)
- Pinjaman tukang dicatat terpisah dan cicilan dipotong otomatis dari upah saat pembayaran
- Hutang supplier muncul sebagai pengeluaran saat dibuat, dilunasi saat bayar
- Alokasi persentase dikonfigurasi di `finance_allocation_configs` dan bisa diubah CEO/Finance
- Dana penalti family gathering tidak bisa dicairkan tanpa record "Penggunaan Dana"
- Semua transaksi punya audit trail lengkap

---

### 4.8 Modul Logistik

#### Fitur Utama
- **Material Master:** Daftar material/barang dengan harga modal, harga jual, dan stok
- **Margin Tracker:** Sistem menghitung otomatis margin profit per material (Harga Jual - Modal)
- **Kebutuhan Material Proyek:** PM/Estimator mencatat kebutuhan material per proyek
- **Manajemen Stok:**
  - Input penerimaan barang (stok bertambah)
  - Input pemakaian barang per proyek (stok berkurang)
  - Alert jika stok di bawah minimum threshold
- **Aset Inventaris:** Pencatatan aset perusahaan (alat, mesin, kendaraan) lengkap dengan kondisi dan lokasi
- **Export:** Export daftar material dan aset ke Excel

#### Business Rules
- Hanya Logistics yang bisa menambah/edit material dan aset
- Estimator bisa membaca daftar material untuk keperluan quotation
- Pemakaian material wajib terhubung ke proyek (tidak ada pemakaian "floating")
- Stok tidak bisa negatif — sistem memblokir input jika qty melebihi stok tersedia

---

### 4.9 Modul Notifikasi (In-App)

#### Trigger Notifikasi
| Event | Penerima |
|---|---|
| Lead follow-up jatuh tempo | Marketing yang bertugas |
| Desain ACC oleh klien | Estimator, PM |
| Quotation disubmit | CEO, PM |
| Quotation approve/reject | Estimator, Marketing |
| Deal dikonfirmasi | PM, CEO, Finance, Logistics |
| Task baru di-assign | Tukang yang bersangkutan |
| Task overdue | PM proyek terkait |
| Form daily task belum diisi (H+jam 21:00) | Tukang yang bersangkutan |
| Penalti dijatuhkan | Tukang + Finance |
| Milestone selesai → QA Form dibuat | Tim QA |
| QA approve/reject | PM proyek terkait |
| QA reject 2x berturut-turut | CEO |
| Pengajuan lembur masuk | PM proyek tukang tersebut |
| Lembur approve oleh PM | Tukang + Finance |
| Jadwal termin mendekat (H-3) | Finance |
| Termin overdue | Finance, CEO |

#### Spesifikasi Teknis
- Notifikasi real-time via **Laravel Echo + Soketi** (self-hosted Pusher-compatible server)
- Badge counter pada bell icon di navbar, update real-time via Echo private channel
- Mark as read per notifikasi atau "mark all as read"
- Riwayat notifikasi tersimpan 90 hari
- Notifikasi overdue dan reminder dikirim via **Laravel Queue + Laravel Scheduler** (cron job harian)

---

### 4.10 CEO Executive Dashboard

#### Widget & Visualisasi
| Widget | Deskripsi |
|---|---|
| Pipeline Funnel | Chart corong jumlah lead per status |
| Active Projects Overview | Card ringkasan proyek berjalan + progres |
| Revenue vs Target | Grafik nilai kontrak vs target bulan berjalan |
| Cash Flow Summary | Grafik pemasukan/pengeluaran 6 bulan terakhir |
| Team Performance | Performa PM (proyek selesai, ontime rate) |
| Penalty Summary | Total penalti tukang + dana family gathering |
| Material Margin Report | Total margin profit logistik |
| Overdue Tasks Heatmap | Visual task yang terlambat per proyek |

---

## 5. Database Schema & Entities

### 5.1 Tabel Utama

#### Users & Auth
```
users
├── id (PK, CUID)
├── name
├── email (UNIQUE)
├── password (hashed)
├── role (ENUM)
├── is_active (BOOLEAN)
└── created_at
```

#### CRM
```
leads
├── id (PK)
├── client_name
├── contact (phone/email)
├── source (Instagram, Referral, dll)
├── priority (HIGH/MEDIUM/LOW)
├── status (LeadStatus ENUM)
├── assigned_to (FK → users)
├── follow_up_date
├── notes
└── timestamps

pipeline_logs
├── id (PK)
├── lead_id (FK → leads)
├── from_status
├── to_status
├── changed_by (FK → users)
├── note
└── created_at
```

#### Desain
```
designs
├── id (PK)
├── lead_id (FK → leads, UNIQUE)
├── status (ENUM)
├── designer_ids (ARRAY of user IDs)
├── design_urls (ARRAY of URLs)
├── brief_note
├── client_acc (BOOLEAN)
├── acc_date
└── timestamps
```

#### Quotation
```
quotations
├── id (PK)
├── lead_id (FK → leads, UNIQUE)
├── total_amount (DECIMAL)
├── status (QuotationStatus ENUM)
├── valid_until
├── version (INT, default 1)
├── created_by (FK → users / estimator)
└── timestamps

quotation_items
├── id (PK)
├── quotation_id (FK → quotations)
├── description
├── qty
├── unit
├── unit_price (DECIMAL)
└── total_price (DECIMAL)

quotation_approvals
├── id (PK)
├── quotation_id (FK)
├── approver_id (FK → users)
├── approver_role (CEO/PM)
├── status (APPROVED/REJECTED)
├── note
└── created_at
```

#### Projects
```
projects
├── id (PK)
├── lead_id (FK → leads, UNIQUE)
├── name
├── pm_id (FK → users)
├── status (ENUM)
├── start_date
├── end_date (nullable)
├── contract_value (DECIMAL)
└── timestamps

milestones
├── id (PK)
├── project_id (FK → projects)
├── name
├── target_date
├── status (ENUM)
├── order (INT)
└── timestamps

tasks
├── id (PK)
├── project_id (FK → projects)
├── milestone_id (FK → milestones, nullable)
├── title
├── description
├── assignee_id (FK → users / tukang)
├── created_by (FK → users / PM)
├── due_date
├── status (ENUM)
├── is_locked (BOOLEAN, default TRUE)
├── rate_per_task (DECIMAL)   — upah per task
├── completed_at (nullable)
└── timestamps

progress_logs
├── id (PK)
├── project_id (FK)
├── logged_by (FK → users / PM)
├── percentage (INT, 0-100)
├── description
├── design_urls (ARRAY, nullable)
└── log_date
```

#### Daily Task & Penalty
```
daily_task_forms
├── id (PK)
├── task_id (FK → tasks)
├── staff_id (FK → users)
├── work_date (DATE)
├── status_update (TaskStatus)
├── notes
└── submitted_at

penalties
├── id (PK)
├── staff_id (FK → users)
├── type (ENUM: DAILY_FORM_MISSING, TASK_OVERDUE)
├── reference_id  — task_id atau daily_form tanggal
├── amount (DECIMAL, default 50000)
├── date_occurred (DATE)
├── is_deducted (BOOLEAN)
└── created_at

family_gathering_fund
├── id (PK)
├── type (INCOME/EXPENSE)
├── amount (DECIMAL)
├── description
├── source_penalty_id (FK → penalties, nullable)
├── recorded_by (FK → users / Finance)
└── created_at
```

#### Overtime (Lembur)
```
overtime_requests
├── id (PK)
├── staff_id (FK → users)
├── project_id (FK → projects)
├── task_id (FK → tasks)
├── hours (DECIMAL)
├── rate_per_hour (DECIMAL)
├── total_amount (DECIMAL)
├── work_date (DATE)
├── reason
├── status (PENDING/APPROVED_PM/APPROVED_FINANCE/REJECTED)
├── pm_approved_by (FK, nullable)
├── pm_approved_at (nullable)
├── finance_approved_by (FK, nullable)
├── finance_approved_at (nullable)
└── created_at
```

#### QA
```
qa_forms
├── id (PK)
├── project_id (FK)
├── milestone_id (FK, UNIQUE)
├── reviewer_id (FK → users / QA, nullable)
├── status (PENDING/APPROVED/REJECTED)
├── checklist_data (JSONB)  — [{label, passed, note}]
├── rejection_count (INT, default 0)
├── notes
├── reviewed_at
└── created_at
```

#### Finance
```
termins
├── id (PK)
├── project_id (FK)
├── milestone_id (FK, nullable)
├── termin_number (INT)
├── percentage (INT)
├── amount (DECIMAL)
├── scheduled_date (DATE)  — selalu Sabtu
├── status (ENUM)
├── invoice_url (nullable)
├── paid_at (nullable)
└── timestamps

finance_transactions
├── id (PK)
├── project_id (FK, nullable)
├── type (ENUM: INCOME/EXPENSE/TERMIN/STAFF_PAY/OVERTIME_PAY/PENALTY_COLLECT)
├── amount (DECIMAL)
├── description
├── reference_id (nullable)  — termin_id / overtime_id / dll
├── date
├── created_by (FK → users)
└── attachments (ARRAY of URLs, nullable)
```

#### Logistics
```
materials
├── id (PK)
├── name
├── unit
├── cost_price (DECIMAL)
├── sell_price (DECIMAL)
├── stock (INT)
├── min_stock (INT)  — threshold alert
├── category
└── timestamps

project_materials
├── id (PK)
├── project_id (FK)
├── material_id (FK)
├── qty_planned (INT)
└── qty_used (INT)

assets
├── id (PK)
├── name
├── category
├── purchase_date
├── value (DECIMAL)
├── condition (GOOD/FAIR/DAMAGED)
├── location
└── notes
```

#### Notifications
```
notifications
├── id (PK)
├── user_id (FK)
├── type (VARCHAR)
├── title
├── message
├── is_read (BOOLEAN)
├── metadata (JSONB)  — {projectId, taskId, etc}
└── created_at
```

### 5.2 Diagram Relasi Antar Modul

```
Lead (1) ──── (1) Design
Lead (1) ──── (1) Quotation
Lead (1) ──── (1) Project

Project (1) ──── (N) Milestones
Project (1) ──── (N) Tasks
Project (1) ──── (N) ProgressLogs
Project (1) ──── (N) Termins
Project (1) ──── (N) FinanceTransactions
Project (1) ──── (N) ProjectMaterials

Milestone (1) ──── (N) Tasks
Milestone (1) ──── (1) QAForm

Task (1) ──── (1) Penalty (nullable)
Task (1) ──── (N) DailyTaskForms
Task (1) ──── (N) OvertimeRequests

User (PM) (1) ──── (N) Projects
User (Staff) (1) ──── (N) Tasks assigned
User (QA) (1) ──── (N) QAForms reviewed
```

---

## 6. Business Logic & State Machines

### 6.1 Lead Status Machine

```
NEW
 └─► CONTACTED
      └─► BRIEFING
           └─► DESIGN_PHASE
                └─► OFFERED
                     ├─► DEAL ──► [Trigger: Create Active Project]
                     └─► LOST (terminal)
```

### 6.2 Quotation Approval Flow

```
Estimator input RAB
      │
      ▼
  DRAFT ──► SUBMITTED
                │
                ▼
          CEO Review
           ├── REJECT ──► kembali ke Estimator (DRAFT)
           └── APPROVE
                   │
                   ▼
              PM Review
               ├── REJECT ──► kembali ke Estimator (DRAFT)
               └── APPROVE
                       │
                       ▼
                SENT TO CLIENT
                   ├── REJECTED (klien) ──► DRAFT (revisi)
                   └── APPROVED (klien) ──► [Trigger: Marketing konfirmasi DEAL]
```

### 6.3 Project Execution Flow

```
Project Created (from Deal)
      │
      ▼
PM Setup Milestones & Tasks
      │
      ▼
Milestone IN_PROGRESS
      │
      ├── Task assigned ke Tukang
      │       ├── Tukang update status task
      │       ├── Tukang isi form daily (wajib tiap hari kerja)
      │       └── Tukang ajukan lembur (opsional)
      │
      ├── PM input Progress Log
      │
      └── PM mark Milestone DONE
               │
               ▼
         QA Form dibuat otomatis
               │
         QA Review Checklist
          ├── REJECT ──► PM notified, perbaikan diperlukan
          │              rejection_count++
          │              (jika rejection_count >= 2 → CEO notified)
          └── APPROVE
                  │
                  ▼
            Milestone COMPLETED
                  │
                  ├── Termin Sabtu unlocked (Finance bisa generate invoice)
                  └── Milestone berikutnya IN_PROGRESS
```

### 6.4 Logika Termin Sabtu

```typescript
// Pseudocode
function getNextSaturday(fromDate: Date): Date {
  const d = new Date(fromDate);
  const dayOfWeek = d.getDay(); // 0=Sun, 6=Sat
  const daysUntilSaturday = dayOfWeek === 6 ? 7 : (6 - dayOfWeek);
  d.setDate(d.getDate() + daysUntilSaturday);
  return d;
}

// PM membuat termin: bebas tentukan persentase
// Validasi: total semua persentase termin = 100%
// scheduledDate otomatis = Sabtu terdekat setelah milestone.targetDate
```

### 6.5 Logika Penalti Harian (Laravel Scheduler + Queue Job)

```
Setiap hari Senin–Sabtu, jam 21:00 WIB:
  Ambil semua tukang yang punya task aktif hari ini
  ─► Cek apakah daily_task_form sudah diisi untuk hari ini
      TIDAK ADA:
        ├── Buat record penalties:
        │     type: DAILY_FORM_MISSING
        │     amount: Rp 50.000
        │     date_occurred: hari ini
        ├── Tambah ke family_gathering_fund (INCOME, Rp 50.000)
        ├── Kirim notifikasi ke tukang: "Form harian belum diisi, penalti Rp 50.000 dijatuhkan"
        └── Kirim notifikasi ke Finance: "Penalti baru masuk ke Dana Family Gathering"
```

### 6.6 Alur Pengajuan Lembur

```
Tukang ajukan lembur
  (input: jam, rate/jam, tanggal, keterangan)
        │
        ▼
  Status: PENDING_PM
        │
  PM Review
   ├── REJECT ──► notif tukang, selesai
   └── APPROVE
           │
           ▼
     Status: PENDING_FINANCE
           │
     Finance Review
      ├── REJECT ──► notif PM + tukang
      └── APPROVE
              │
              ▼
        Status: COMPLETED
        Finance catat sebagai EXPENSE (OVERTIME_PAY)
        Notifikasi ke tukang: lembur disetujui
```

---

## 7. Role-Based Access Control (RBAC)

### 7.1 Matriks Akses (CRUD)

```
MODUL / FITUR            │ CEO │ MKT │ DES │ EST │ PM  │ QA  │ FIN │ LOG │ STF
─────────────────────────┼─────┼─────┼─────┼─────┼─────┼─────┼─────┼─────┼─────
CRM – Lead               │  R  │CRUD │  R  │  R  │  R  │  -  │  -  │  -  │  -
CRM – Pipeline Status    │  R  │  U  │  -  │  -  │  R  │  -  │  -  │  -  │  -
CRM – Pipeline Log       │  R  │  R  │  -  │  -  │  R  │  -  │  -  │  -  │  -
Design Brief             │  R  │  R  │CRUD │  R  │  R  │  R  │  -  │  -  │  -
Design – Client ACC      │  R  │  U  │  U  │  -  │  R  │  -  │  -  │  -  │  -
Quotation                │  R  │  R  │  R  │CRUD │  R  │  -  │  R  │  -  │  -
Quotation Approval       │  U  │  -  │  -  │  -  │  U  │  -  │  -  │  -  │  -
Project (overview)       │  R  │  R  │  R  │  R  │CRUD │  R  │  R  │  R  │  R*
Milestone                │  R  │  -  │  -  │  R  │CRUD │  R  │  -  │  -  │  -
Task – Create/Edit       │  R  │  -  │  -  │  -  │CRUD │  -  │  -  │  -  │  -
Task – Update Status     │  R  │  -  │  -  │  -  │  U  │  -  │  -  │  -  │  U†
Daily Task Form          │  R  │  -  │  -  │  -  │  R  │  -  │  -  │  -  │  CR
Overtime Request         │  R  │  -  │  -  │  -  │ RU  │  -  │ RU  │  -  │  CR
Progress Log             │  R  │  -  │  R  │  -  │CRUD │  R  │  R  │  -  │  -
QA Form                  │  R  │  -  │  -  │  -  │  R  │CRUD │  -  │  -  │  -
Finance – Transaction    │  R  │  -  │  -  │  -  │  R  │  -  │CRUD │  -  │  -
Finance – Termin         │  R  │  -  │  -  │  -  │  C  │  R  │ RU  │  -  │  -
Finance – Family Fund    │  R  │  -  │  -  │  -  │  -  │  -  │CRUD │  -  │  -
Penalty – View           │  R  │  -  │  -  │  -  │  R  │  -  │  R  │  -  │  R*
Material – Master        │  R  │  -  │  -  │  R  │  R  │  -  │  -  │CRUD │  -
Material – Stok          │  R  │  -  │  -  │  -  │  R  │  -  │  -  │CRUD │  -
Project Material         │  R  │  -  │  -  │  C  │ RU  │  -  │  -  │CRUD │  -
Asset Inventory          │  R  │  -  │  -  │  -  │  R  │  -  │  R  │CRUD │  -
Notification (own)       │  R  │  R  │  R  │  R  │  R  │  R  │  R  │  R  │  R
Analytics – Executive    │FULL │  -  │  -  │  -  │  -  │  -  │  -  │  -  │  -
Analytics – Per Divisi   │FULL │  P  │  P  │  P  │  P  │  P  │  P  │  P  │  -
─────────────────────────┴─────┴─────┴─────┴─────┴─────┴─────┴─────┴─────┴─────

C=Create  R=Read  U=Update  D=Delete  -=No Access
R* = Hanya data milik user tersebut
U† = Update status saja (field lain immutable)
P  = Partial dashboard sesuai divisi masing-masing
```

### 7.2 Implementasi Guard

```typescript
// Contoh penggunaan decorator
@Get('projects/:id/qa')
@Roles(Role.CEO, Role.PROJECT_MANAGER, Role.QA)
@UseGuards(JwtAuthGuard, RolesGuard)
async getQAForm(@Param('id') id: string) { ... }

// Field Staff — hanya bisa update status task milik sendiri
@Patch('tasks/:id/status')
@Roles(Role.FIELD_STAFF, Role.PROJECT_MANAGER)
@UseGuards(JwtAuthGuard, RolesGuard, TaskOwnerGuard)
async updateTaskStatus(...) { ... }
```

---

## 8. UI/UX Design System

### 8.1 Identitas Visual Daiku
- **Gaya:** Minimalis, bersih, profesional, enterprise-grade
- **Warna Dominan:** Putih (#FFFFFF) sebagai latar utama
- **Warna Aksen Utama:** Daiku Yellow (#F5C518)
- **Warna Pendukung:** Abu-abu gelap (#1A1A1A), Abu-abu muda (#F5F5F5), Border (#E8E8E8)
- **Status Colors:** Success (#22C55E), Warning (#F59E0B), Error (#EF4444), Info (#3B82F6)

### 8.2 Token Warna (Tailwind Config)
```js
colors: {
  daiku: {
    yellow: '#F5C518',
    'yellow-light': '#FFF9E6',
    'yellow-dark': '#D4A800',
    cream:  '#FFFDF0',
    dark:   '#1A1A1A',
    gray:   '#F5F5F5',
    border: '#E8E8E8',
    muted:  '#6B7280',
  }
}
```

### 8.3 Komponen Utama
- **Sidebar Navigasi:** Fixed left sidebar dengan grouping per divisi, badge notifikasi
- **Top Bar:** Breadcrumb + user avatar + notification bell
- **Card:** White background, shadow-sm, rounded-lg, border border-daiku-border
- **Primary Button:** Background daiku-yellow, text dark, hover daiku-yellow-dark
- **Status Badge:** Chip berwarna sesuai status (PENDING = gray, APPROVED = green, dll)
- **Table:** Striped rows dengan daiku-gray, header background daiku-yellow-light
- **Modal/Dialog:** shadcn Dialog, centered, max-w-lg

### 8.4 Layout per Role
- **CEO:** Dashboard utama + akses ke semua sidebar menu
- **Field Staff:** Tampilan sederhana — hanya task list & form daily (mobile-friendly priority)
- **Finance:** Dashboard cash flow + termin calendar view
- **PM:** Gantt-like milestone view + task board per proyek

---

## 9. Security Requirements

### 9.1 Authentication
- Session-based authentication via **Laravel Breeze** (lebih aman untuk web app internal)
- CSRF protection otomatis dari Laravel (semua form dan request Inertia terlindungi)
- Bcrypt hashing untuk password (cost factor default Laravel: 12)
- Session invalidasi saat logout, remember token opsional

### 9.2 Authorization
- Setiap route di-protect dengan middleware `auth` + `role:` via **Spatie Laravel Permission**
- Policy Laravel untuk resource-level check (task milik sendiri, proyek milik PM, dll)
- Gate check di service layer untuk validasi kepemilikan

### 9.3 Input Validation
- Semua request divalidasi via **Laravel Form Request** (terpisah per aksi)
- Sanitasi input otomatis via Laravel + Eloquent mass assignment protection (`$fillable`)
- Rate limiting: 60 request/menit per user via Laravel `throttle` middleware

### 9.4 Audit Trail
- Semua aksi sensitif dicatat: approval quotation, QA decision, perubahan finance, penjatuhan penalti
- Log menyimpan: user ID, timestamp, IP address, action type, data before/after
- Audit log tidak bisa dihapus oleh siapapun (termasuk CEO)

### 9.5 Data Protection
- HTTPS wajib di semua environment (Nginx + Let's Encrypt / SSL cert)
- Environment variables via `.env` (tidak di-commit ke repository)
- Secret management menggunakan environment injection di CI/CD
- Database tidak expose ke public internet (hanya accessible dari backend container)
- Backup database terenkripsi, disimpan di lokasi terpisah

---

## 10. Development Process & Roadmap

### 10.1 Branching Strategy (Git Flow)
```
main           ──► Production only, protected branch
  └── develop  ──► Staging, auto-deploy
       └── feature/[modul-nama]   ──► Development aktif
       └── hotfix/[issue]         ──► Emergency fix production
```

### 10.2 Urutan Development (Phase)

#### Phase 1 — Foundation (Minggu 1–2)
- [ ] Setup Laravel 11 + Inertia.js v2 + React 18 + TypeScript
- [ ] Setup Docker Compose (MySQL, Redis, Soketi, app)
- [ ] Install & konfigurasi: Spatie Permission, Telescope, Horizon, Ziggy
- [ ] Database migration semua tabel + seed roles & user awal
- [ ] Modul Auth (Laravel Breeze, login, redirect per role)
- [ ] User management (CRUD, assign role via Spatie)
- [ ] CI/CD pipeline (GitHub Actions → staging)
- [ ] Base UI: AppLayout, sidebar per role, design system Daiku Yellow

#### Phase 2 — Presales Flow (Minggu 3–4)
- [ ] Modul CRM (Lead, Pipeline, Follow-up notifikasi)
- [ ] Modul Design (Brief, link URL, ACC konfirmasi)
- [ ] Modul Quotation (RAB builder, dual approval CEO→PM, PDF export DomPDF)
- [ ] Konversi Lead → Active Project otomatis saat Deal

#### Phase 3 — Execution Core (Minggu 5–8)
- [ ] Modul Project Management (Project, Milestone, Timeline)
- [ ] Modul Task (assign, lock mechanism, status update Tukang)
- [ ] Daily Task Form + DailyPenaltyJob (Laravel Scheduler jam 21:00)
- [ ] FamilyGatheringFund: akumulasi penalti otomatis
- [ ] Pengajuan & approval lembur (Tukang → PM → Finance)
- [ ] Modul QA (form checklist, blocking, rejection counter)
- [ ] Progress Log PM

#### Phase 4 — Finance & Logistics (Minggu 9–10)
- [ ] Modul Finance (cash flow, termin Sabtu, invoice PDF, upah tukang)
- [ ] Dana Family Gathering dashboard + pencatatan penggunaan
- [ ] Export Excel laporan keuangan (Laravel Excel)
- [ ] Modul Logistik (material + margin, stok, aset inventaris)

#### Phase 5 — Analytics & Notifikasi (Minggu 11)
- [ ] Real-time notifikasi (Laravel Echo + Soketi + Laravel Notifications)
- [ ] Semua trigger notifikasi (penalty, QA, termin, overtime, dll)
- [ ] CEO Executive Dashboard (semua widget Recharts)
- [ ] Analytics partial per divisi

#### Phase 6 — Testing, UAT & Launch (Minggu 12–13)
- [ ] Unit & feature test Pest PHP (target coverage ≥70%)
- [ ] RBAC test menyeluruh semua role
- [ ] UAT 4 sesi bersama tim Daiku Interior
- [ ] Bug fix & regression test
- [ ] Security hardening (rate limit, CSRF, audit log)
- [ ] Performance tuning (eager loading, N+1 fix, pagination)
- [ ] Deploy production + training user
- [ ] Go-live + monitoring Horizon & Telescope

### 10.3 Definition of Done (DoD)
Setiap fitur dinyatakan selesai jika:
1. Kode sudah di-review (pull request)
2. Unit test coverage minimal 70%
3. RBAC sudah diuji untuk semua role yang relevan
4. Sudah di-deploy ke staging dan tidak ada bug blocker
5. UAT sign-off dari PIC divisi terkait

---

## 11. Infrastructure & Deployment

### 11.1 Environment Setup
```
Local Dev   ──► Docker Compose (semua service lokal)
Staging     ──► VPS / Cloud VM (mirror production, data dummy)
Production  ──► VPS / Cloud VM (data real, backup harian)
```

### 11.2 Docker Compose Services
```yaml
services:
  mysql:   # MySQL 8.0
  redis:      # Redis 7 (cache + queue driver)
  app:        # Laravel 11 (backend + Inertia frontend dalam satu container)
  worker:     # Laravel Queue Worker (penalty job, notif job, termin reminder)
  soketi:     # Soketi — self-hosted Pusher-compatible server untuk Echo real-time
  nginx:      # Reverse proxy + SSL termination
```

> **Catatan:** Berbeda dengan Node.js stack sebelumnya, Laravel + Inertia hanya butuh **satu container app** untuk backend dan frontend sekaligus — tidak ada container terpisah untuk FE dan BE. Ini menyederhanakan deployment dan mengurangi biaya server.

### 11.3 Scalability Plan (Multi-Cabang)
- Arsitektur sudah didesain untuk multi-tenant dengan field `branch_id` yang dapat ditambahkan ke tabel utama saat ekspansi
- Load balancer Nginx sudah siap untuk scale horizontal
- Redis digunakan sebagai shared cache/session untuk multiple instance backend

### 11.4 Backup & Recovery
- Backup MySQL otomatis: `mysqldump` setiap tengah malam
- Retensi backup: 30 hari
- Recovery target: RTO < 4 jam, RPO < 24 jam
- Backup disimpan di storage terpisah dari server production

---

## 12. Open Items (TBD)

Item berikut akan dikonfirmasi saat kick-off atau sprint planning:

| # | Item | PIC | Target Konfirmasi |
|---|---|---|---|
| 1 | Detail form harian tukang (field apa saja yang wajib diisi) | PM + Dev | Sebelum Phase 3 |
| 2 | Template checklist QA per tipe milestone | QA Team | Sebelum Phase 3 |
| 3 | Rate upah default per task / konfigurasi di sistem | Finance + PM | Sebelum Phase 3 |
| 4 | Template PDF invoice termin (desain layout) | Finance + Marketing | Sebelum Phase 4 |
| 5 | Template PDF quotation / RAB (layout resmi) | Estimator | Sebelum Phase 2 |
| 6 | Definisi lengkap item checklist QA (minimal per milestone) | QA Team | Sebelum Phase 3 |
| 7 | Kebijakan overtime: rate standar atau input manual tiap pengajuan | Finance + PM | Sebelum Phase 3 |
| 8 | Integrasi WhatsApp/email notifikasi di fase mendatang | CEO | Roadmap v2.0 |
| 9 | Rencana struktur data cabang (field tambahan apa saja) | CEO + Dev | Sebelum ekspansi |

---

*Dokumen ini merupakan living document. Setiap perubahan spesifikasi harus melalui change request dan disetujui oleh Lead Architect sebelum diimplementasikan.*

---

**Daiku Interior Enterprise System — PRD v1.0.0 — Initial Release**
*Confidential — Internal Use Only*

---

## 13. DOCUMENT CHANGELOG

| Versi | Tanggal | Status | Penulis | Deskripsi Perubahan |
|---|---|---|---|---|
| v1.0.0 | Agustus 2026 | Initial Release | Ido Refael Siregar | Dokumen pertama. Mencakup arsitektur sistem, stack (Laravel + Inertia + React), skema database, RBAC, alur bisnis, dan disesuaikan dengan data operasional nyata Daiku Interior (CRM, Design Report, Keuangan, Operasional Desain). |

### Catatan Versi
- **v1.0.0** adalah versi awal (*initial release*) sebelum memasuki siklus revisi.
- Perubahan apapun setelah dokumen ini di-review oleh stakeholder akan menghasilkan **v1.1.0** (minor revision) atau **v2.0.0** (major revision jika ada perubahan arsitektur besar).
- Konvensi: `MAJOR.MINOR.PATCH` — contoh: v1.1.0 = ada penambahan fitur, v1.0.1 = ada koreksi kecil/typo.

