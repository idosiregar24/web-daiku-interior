-- ============================================================
-- DAIKU INTERIOR — Database Schema
-- Version    : v1.0.0 — Initial Release
-- Status     : Draft · Pre-Revision
-- Engine     : MySQL 8.0
-- Prepared by: Ido Refael Siregar
-- Last Update: Agustus 2026
-- ============================================================
-- CHANGELOG
-- v1.0.0 | Agustus 2026 | Initial Release
--   - Schema awal 30 tabel mencakup seluruh modul sistem
--   - Disesuaikan dengan data operasional nyata Daiku Interior
--   - Tambah: bank_accounts, staff_loans, supplier_debts,
--     family_gathering_fund, finance_allocation_configs
--   - Priority lead: HOT/WARM/COLD (dari CRM nyata)
--   - Task status: ONPROGRESS/PENGECEKAN/DONE/OVER
--   - Design status: 13 tahap sesuai alur operasional
-- ============================================================
-- ─── AUTH & USERS ───────────────────────────────────────────

CREATE TABLE users (
  id          VARCHAR(26)  NOT NULL PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(100) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('CEO','MARKETING','DESIGNER','ESTIMATOR','PROJECT_MANAGER','QA','FINANCE','LOGISTICS','FIELD_STAFF') NOT NULL,
  salary      DECIMAL(12,2) NULL DEFAULT 0,      -- gaji pokok tetap (dari data nyata)
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NULL,
  updated_at  TIMESTAMP    NULL
);

-- ─── BANK ACCOUNTS (Multi-rekening) ─────────────────────────
-- Dari data nyata: BCA 5835, BCA 4342, Mandiri, BRI, BNI, Mandiri PT, dll

CREATE TABLE bank_accounts (
  id           VARCHAR(26)  NOT NULL PRIMARY KEY,
  bank_name    VARCHAR(50)  NOT NULL,             -- BCA, Mandiri, BRI, BNI
  account_no   VARCHAR(30)  NOT NULL,             -- 5835, 4342, dll
  label        VARCHAR(50)  NOT NULL,             -- "BCA 5835", "Mandiri PT"
  balance      DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NULL,
  updated_at   TIMESTAMP    NULL
);

-- ─── CRM / PRESALES ─────────────────────────────────────────
-- Priority: Hot/Warm/Cold (dari data CRM nyata, bukan High/Medium/Low)
-- Status: disesuaikan dengan pipeline Daiku

CREATE TABLE leads (
  id              VARCHAR(26)  NOT NULL PRIMARY KEY,
  client_name     VARCHAR(100) NOT NULL,
  contact         VARCHAR(100) NOT NULL,
  source          ENUM('INSTAGRAM','TIKTOK','REFERRAL','WALK_IN','WHATSAPP','MARKETPLACE','IKLAN_SOSMED','WEBSITE','LAINNYA') NOT NULL,
  category        ENUM('RESIDENTIAL','KOMERSIAL','DEVELOPER','KONTRAKTOR','LAINNYA') NULL,
  layanan         ENUM('BUILD_INTERIOR_RUMAH','BUILD_INTERIOR_CAFE','BUILD_INTERIOR_KANTOR','BUILD_INTERIOR_TOKO','BUILD_EXTERIOR','DESAIN_INTERIOR','DESAIN_EXTERIOR','LAINNYA') NULL,
  city            VARCHAR(50)  NULL,
  gender          ENUM('LAKI_LAKI','PEREMPUAN') NULL,
  priority        ENUM('HOT','WARM','COLD') NOT NULL DEFAULT 'COLD',
  status          ENUM('FOLLOW_UP','DEAL_DESAIN','CLOSING','LOST') NOT NULL DEFAULT 'FOLLOW_UP',
  assigned_to     VARCHAR(26)  NULL,              -- marketing/sales yang handle
  detail_order    TEXT         NULL,              -- detail kebutuhan klien
  alasan_lost     VARCHAR(255) NULL,
  follow_up_date  DATE         NULL,
  notes           TEXT         NULL,
  created_at      TIMESTAMP    NULL,
  updated_at      TIMESTAMP    NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE pipeline_logs (
  id           VARCHAR(26)  NOT NULL PRIMARY KEY,
  lead_id      VARCHAR(26)  NOT NULL,
  from_status  ENUM('FOLLOW_UP','DEAL_DESAIN','CLOSING','LOST') NULL,
  to_status    ENUM('FOLLOW_UP','DEAL_DESAIN','CLOSING','LOST') NOT NULL,
  changed_by   VARCHAR(26)  NOT NULL,
  note         TEXT         NULL,
  created_at   TIMESTAMP    NULL,
  FOREIGN KEY (lead_id)    REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── DESIGN ─────────────────────────────────────────────────
-- Status disesuaikan dengan operasional nyata tim desain

CREATE TABLE designs (
  id            VARCHAR(26)   NOT NULL PRIMARY KEY,
  lead_id       VARCHAR(26)   NOT NULL UNIQUE,
  pic_id        VARCHAR(26)   NULL,               -- PIC utama (NISA, YOLA, UMI, dll)
  jenis_project ENUM('TOKO','CAFE','RENOVASI','KAMAR_SET','KITCHEN_SET','KANTOR','ARSITEKTURAL','RUANG_TAMU_TV','RETAIL_TOKO','LAINNYA') NULL,
  status        ENUM('BRIEF','DESAIN','WAITING_ACC_DESAIN','REVISI_DESAIN','ACC_DESAIN','GAMBAR_RAB','PEMBUATAN_PENAWARAN','WAITING_ACC_PENAWARAN','PRODUKSI','REJECT_PRODUKSI','DONE_PRODUKSI','HOLD_CLIENT','REVISI_CLIENT') NOT NULL DEFAULT 'BRIEF',
  target_hari   INT           NULL,               -- target durasi pengerjaan (hari)
  start_date    DATE          NULL,
  deadline      DATE          NULL,
  delay_hari    INT           NOT NULL DEFAULT 0,
  design_urls   JSON          NULL,               -- link Google Drive / Figma
  brief_note    TEXT          NULL,
  problem       TEXT          NULL,               -- kendala / masalah
  client_acc    TINYINT(1)    NOT NULL DEFAULT 0,
  acc_date      DATE          NULL,
  created_at    TIMESTAMP     NULL,
  updated_at    TIMESTAMP     NULL,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (pic_id)  REFERENCES users(id) ON DELETE SET NULL
);

-- Sub-staff yang ikut mengerjakan satu proyek desain
CREATE TABLE design_staff (
  id         VARCHAR(26) NOT NULL PRIMARY KEY,
  design_id  VARCHAR(26) NOT NULL,
  user_id    VARCHAR(26) NOT NULL,
  role_note  VARCHAR(100) NULL,                   -- "3D modeling", "gambar RAB", dll
  FOREIGN KEY (design_id) REFERENCES designs(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
);

-- ─── QUOTATION / RAB ────────────────────────────────────────

CREATE TABLE quotations (
  id           VARCHAR(26)     NOT NULL PRIMARY KEY,
  lead_id      VARCHAR(26)     NOT NULL UNIQUE,
  total_amount DECIMAL(15,2)   NOT NULL DEFAULT 0,
  status       ENUM('DRAFT','SUBMITTED','CEO_REVIEW','PM_REVIEW','SENT_TO_CLIENT','APPROVED','REJECTED') NOT NULL DEFAULT 'DRAFT',
  valid_until  DATE            NULL,
  version      INT             NOT NULL DEFAULT 1,
  created_by   VARCHAR(26)     NOT NULL,
  created_at   TIMESTAMP       NULL,
  updated_at   TIMESTAMP       NULL,
  FOREIGN KEY (lead_id)    REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE quotation_items (
  id            VARCHAR(26)   NOT NULL PRIMARY KEY,
  quotation_id  VARCHAR(26)   NOT NULL,
  description   VARCHAR(255)  NOT NULL,
  qty           INT           NOT NULL DEFAULT 1,
  unit          VARCHAR(20)   NOT NULL,
  unit_price    DECIMAL(15,2) NOT NULL,
  total_price   DECIMAL(15,2) NOT NULL,
  sort_order    INT           NOT NULL DEFAULT 0,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
);

CREATE TABLE quotation_approvals (
  id            VARCHAR(26)  NOT NULL PRIMARY KEY,
  quotation_id  VARCHAR(26)  NOT NULL,
  approver_id   VARCHAR(26)  NOT NULL,
  approver_role ENUM('CEO','PROJECT_MANAGER') NOT NULL,
  status        ENUM('APPROVED','REJECTED')   NOT NULL,
  note          TEXT         NULL,
  created_at    TIMESTAMP    NULL,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY (approver_id)  REFERENCES users(id)      ON DELETE CASCADE
);

-- ─── PROJECTS ───────────────────────────────────────────────

CREATE TABLE projects (
  id              VARCHAR(26)   NOT NULL PRIMARY KEY,
  lead_id         VARCHAR(26)   NOT NULL UNIQUE,
  name            VARCHAR(150)  NOT NULL,
  jenis_project   ENUM('TOKO','CAFE','RENOVASI','KAMAR_SET','KITCHEN_SET','KANTOR','ARSITEKTURAL','RUANG_TAMU_TV','RETAIL_TOKO','LAINNYA') NULL,
  pm_id           VARCHAR(26)   NOT NULL,
  status          ENUM('ACTIVE','ON_HOLD','COMPLETED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  start_date      DATE          NOT NULL,
  end_date        DATE          NULL,
  contract_value  DECIMAL(15,2) NOT NULL,
  created_at      TIMESTAMP     NULL,
  updated_at      TIMESTAMP     NULL,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (pm_id)   REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE milestones (
  id           VARCHAR(26)  NOT NULL PRIMARY KEY,
  project_id   VARCHAR(26)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  target_date  DATE         NOT NULL,
  status       ENUM('PENDING','IN_PROGRESS','QA_WAITING','COMPLETED','OVERDUE') NOT NULL DEFAULT 'PENDING',
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NULL,
  updated_at   TIMESTAMP    NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- ─── TASKS ──────────────────────────────────────────────────
-- Status disesuaikan dari data daily task nyata:
-- DONE / ONPROGRESS / PENGECEKAN / OVER (= overdue)

CREATE TABLE tasks (
  id             VARCHAR(26)   NOT NULL PRIMARY KEY,
  project_id     VARCHAR(26)   NOT NULL,
  milestone_id   VARCHAR(26)   NULL,
  title          VARCHAR(150)  NOT NULL,
  description    TEXT          NULL,
  assignee_id    VARCHAR(26)   NOT NULL,
  created_by     VARCHAR(26)   NOT NULL,
  due_date       DATE          NOT NULL,
  priority       ENUM('HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  status         ENUM('PENDING','ONPROGRESS','PENGECEKAN','DONE','OVER') NOT NULL DEFAULT 'PENDING',
  is_locked      TINYINT(1)    NOT NULL DEFAULT 1,
  kendala        TEXT          NULL,              -- kendala yang dihadapi
  note           TEXT          NULL,              -- catatan tambahan
  rate_per_task  DECIMAL(12,2) NOT NULL DEFAULT 0,
  completed_at   TIMESTAMP     NULL,
  created_at     TIMESTAMP     NULL,
  updated_at     TIMESTAMP     NULL,
  FOREIGN KEY (project_id)   REFERENCES projects(id)   ON DELETE CASCADE,
  FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE SET NULL,
  FOREIGN KEY (assignee_id)  REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE CASCADE
);

-- ─── PROGRESS LOGS ──────────────────────────────────────────

CREATE TABLE progress_logs (
  id           VARCHAR(26)  NOT NULL PRIMARY KEY,
  project_id   VARCHAR(26)  NOT NULL,
  logged_by    VARCHAR(26)  NOT NULL,
  percentage   TINYINT      NOT NULL DEFAULT 0,
  description  TEXT         NOT NULL,
  ref_urls     JSON         NULL,
  log_date     DATE         NOT NULL,
  created_at   TIMESTAMP    NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (logged_by)  REFERENCES users(id)    ON DELETE CASCADE
);

-- ─── DAILY TASK FORMS ───────────────────────────────────────

CREATE TABLE daily_task_forms (
  id              VARCHAR(26)  NOT NULL PRIMARY KEY,
  task_id         VARCHAR(26)  NOT NULL,
  staff_id        VARCHAR(26)  NOT NULL,
  work_date       DATE         NOT NULL,
  status_update   ENUM('PENDING','ONPROGRESS','PENGECEKAN','DONE','OVER') NOT NULL,
  kendala         TEXT         NULL,
  note            TEXT         NULL,
  submitted_at    TIMESTAMP    NULL,
  created_at      TIMESTAMP    NULL,
  UNIQUE KEY unique_task_date (task_id, work_date),
  FOREIGN KEY (task_id)  REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── PENALTIES ──────────────────────────────────────────────

CREATE TABLE penalties (
  id              VARCHAR(26)   NOT NULL PRIMARY KEY,
  staff_id        VARCHAR(26)   NOT NULL,
  type            ENUM('DAILY_FORM_MISSING') NOT NULL,
  task_id         VARCHAR(26)   NULL,
  reference_date  DATE          NOT NULL,
  amount          DECIMAL(12,2) NOT NULL DEFAULT 50000,
  is_deducted     TINYINT(1)    NOT NULL DEFAULT 0,
  date_occurred   DATE          NOT NULL,
  created_at      TIMESTAMP     NULL,
  FOREIGN KEY (staff_id) REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (task_id)  REFERENCES tasks(id)  ON DELETE SET NULL
);

CREATE TABLE family_gathering_fund (
  id                 VARCHAR(26)   NOT NULL PRIMARY KEY,
  type               ENUM('INCOME','EXPENSE') NOT NULL,
  amount             DECIMAL(12,2) NOT NULL,
  description        VARCHAR(255)  NOT NULL,
  source_penalty_id  VARCHAR(26)   NULL,
  recorded_by        VARCHAR(26)   NOT NULL,
  created_at         TIMESTAMP     NULL,
  FOREIGN KEY (source_penalty_id) REFERENCES penalties(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by)       REFERENCES users(id)     ON DELETE CASCADE
);

-- ─── OVERTIME ───────────────────────────────────────────────

CREATE TABLE overtime_requests (
  id                   VARCHAR(26)   NOT NULL PRIMARY KEY,
  staff_id             VARCHAR(26)   NOT NULL,
  project_id           VARCHAR(26)   NOT NULL,
  task_id              VARCHAR(26)   NOT NULL,
  work_date            DATE          NOT NULL,
  hours                DECIMAL(4,2)  NOT NULL,
  rate_per_hour        DECIMAL(12,2) NOT NULL,
  total_amount         DECIMAL(12,2) NOT NULL,
  reason               TEXT          NOT NULL,
  status               ENUM('PENDING_PM','APPROVED_PM','PENDING_FINANCE','APPROVED_FINANCE','REJECTED') NOT NULL DEFAULT 'PENDING_PM',
  pm_approved_by       VARCHAR(26)   NULL,
  pm_approved_at       TIMESTAMP     NULL,
  finance_approved_by  VARCHAR(26)   NULL,
  finance_approved_at  TIMESTAMP     NULL,
  reject_note          TEXT          NULL,
  created_at           TIMESTAMP     NULL,
  updated_at           TIMESTAMP     NULL,
  FOREIGN KEY (staff_id)            REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (project_id)          REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id)             REFERENCES tasks(id)    ON DELETE CASCADE,
  FOREIGN KEY (pm_approved_by)      REFERENCES users(id)    ON DELETE SET NULL,
  FOREIGN KEY (finance_approved_by) REFERENCES users(id)    ON DELETE SET NULL
);

-- ─── QA ─────────────────────────────────────────────────────

CREATE TABLE qa_forms (
  id               VARCHAR(26)  NOT NULL PRIMARY KEY,
  project_id       VARCHAR(26)  NOT NULL,
  milestone_id     VARCHAR(26)  NOT NULL UNIQUE,
  reviewer_id      VARCHAR(26)  NULL,
  status           ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  checklist_data   JSON         NULL,
  rejection_count  TINYINT      NOT NULL DEFAULT 0,
  notes            TEXT         NULL,
  reviewed_at      TIMESTAMP    NULL,
  created_at       TIMESTAMP    NULL,
  updated_at       TIMESTAMP    NULL,
  FOREIGN KEY (project_id)   REFERENCES projects(id)   ON DELETE CASCADE,
  FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewer_id)  REFERENCES users(id)      ON DELETE SET NULL
);

-- ─── FINANCE ────────────────────────────────────────────────

CREATE TABLE termins (
  id              VARCHAR(26)   NOT NULL PRIMARY KEY,
  project_id      VARCHAR(26)   NOT NULL,
  milestone_id    VARCHAR(26)   NULL,
  termin_number   INT           NOT NULL,
  percentage      TINYINT       NOT NULL,
  amount          DECIMAL(15,2) NOT NULL,
  dp_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- Down Payment
  pelunasan       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- Pembayaran masuk
  sisa_piutang    DECIMAL(15,2) GENERATED ALWAYS AS (amount - dp_amount - pelunasan) STORED,
  scheduled_date  DATE          NOT NULL,             -- selalu Sabtu
  status          ENUM('SCHEDULED','INVOICED','PAID','OVERDUE') NOT NULL DEFAULT 'SCHEDULED',
  bank_account_id VARCHAR(26)   NULL,                 -- rekening penerima
  invoice_url     VARCHAR(255)  NULL,
  paid_at         TIMESTAMP     NULL,
  created_at      TIMESTAMP     NULL,
  updated_at      TIMESTAMP     NULL,
  FOREIGN KEY (project_id)     REFERENCES projects(id)      ON DELETE CASCADE,
  FOREIGN KEY (milestone_id)   REFERENCES milestones(id)    ON DELETE SET NULL,
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
);

-- Sub-kategori transaksi disesuaikan dari data keuangan nyata
CREATE TABLE finance_transactions (
  id               VARCHAR(26)   NOT NULL PRIMARY KEY,
  project_id       VARCHAR(26)   NULL,
  bank_account_id  VARCHAR(26)   NULL,               -- dari/ke rekening mana
  type             ENUM('PEMASUKAN','PENGELUARAN') NOT NULL,
  kategori         ENUM('DOWN_PAYMENT','TERMIN','OPERASIONAL','PINJAMAN','BELI_BAHAN','ANGSURAN','GAJI_KARYAWAN','LEMBUR_BONUS','LOGISTIK','HUTANG_IDEAL','PEGANGAN','JASA_DESAIN','VENDOR','PINDAH_DANA','KONSUMSI','CONSUMABLE','PERALATAN_ASET','BBM','OWNER','PENALTY_COLLECT','LAINNYA') NOT NULL,
  amount           DECIMAL(15,2) NOT NULL,
  qty              INT           NOT NULL DEFAULT 1,
  unit_price       DECIMAL(15,2) NULL,
  description      VARCHAR(255)  NOT NULL,
  reference_id     VARCHAR(26)   NULL,
  date             DATE          NOT NULL,
  created_by       VARCHAR(26)   NOT NULL,
  attachments      JSON          NULL,
  created_at       TIMESTAMP     NULL,
  FOREIGN KEY (project_id)      REFERENCES projects(id)      ON DELETE SET NULL,
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)      REFERENCES users(id)         ON DELETE CASCADE
);

-- Konfigurasi alokasi % otomatis dari nilai proyek
-- Dari data nyata: consumable 1%, operasional 2%, penyusutan 1%, listrik 1%,
-- konsumsi 1%, gaji 12%, lembur 1%, bonus 1%, angsuran 1%
CREATE TABLE finance_allocation_configs (
  id          VARCHAR(26)   NOT NULL PRIMARY KEY,
  label       VARCHAR(50)   NOT NULL,               -- "gaji", "consumable", dll
  percentage  DECIMAL(5,2)  NOT NULL,               -- 12.00, 1.00, 2.00, dll
  kategori    VARCHAR(50)   NOT NULL,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  TIMESTAMP     NULL
);

-- ─── STAFF LOANS (Pinjaman Tukang) ──────────────────────────
-- Dari data nyata: ada pinjaman per tukang + cicilan

CREATE TABLE staff_loans (
  id              VARCHAR(26)   NOT NULL PRIMARY KEY,
  staff_id        VARCHAR(26)   NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,            -- total pinjaman
  paid_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,  -- sudah dibayar
  remaining       DECIMAL(12,2) GENERATED ALWAYS AS (amount - paid_amount) STORED,
  description     TEXT          NULL,
  bank_account_id VARCHAR(26)   NULL,
  created_at      TIMESTAMP     NULL,
  updated_at      TIMESTAMP     NULL,
  FOREIGN KEY (staff_id)        REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
);

CREATE TABLE staff_loan_payments (
  id          VARCHAR(26)   NOT NULL PRIMARY KEY,
  loan_id     VARCHAR(26)   NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  paid_date   DATE          NOT NULL,
  note        VARCHAR(255)  NULL,
  created_at  TIMESTAMP     NULL,
  FOREIGN KEY (loan_id) REFERENCES staff_loans(id) ON DELETE CASCADE
);

-- ─── SUPPLIER DEBTS (Hutang ke Supplier) ────────────────────
-- Dari data nyata: hutang ideal, hutang kaca

CREATE TABLE supplier_debts (
  id             VARCHAR(26)   NOT NULL PRIMARY KEY,
  supplier_name  VARCHAR(100)  NOT NULL,             -- "Ideal", "Kaca", dll
  total_amount   DECIMAL(15,2) NOT NULL,
  paid_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  remaining      DECIMAL(15,2) GENERATED ALWAYS AS (total_amount - paid_amount) STORED,
  project_id     VARCHAR(26)   NULL,
  description    TEXT          NULL,
  due_date       DATE          NULL,
  created_at     TIMESTAMP     NULL,
  updated_at     TIMESTAMP     NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
);

CREATE TABLE supplier_debt_payments (
  id              VARCHAR(26)   NOT NULL PRIMARY KEY,
  debt_id         VARCHAR(26)   NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  paid_date       DATE          NOT NULL,
  bank_account_id VARCHAR(26)   NULL,
  note            VARCHAR(255)  NULL,
  created_at      TIMESTAMP     NULL,
  FOREIGN KEY (debt_id)         REFERENCES supplier_debts(id) ON DELETE CASCADE,
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)  ON DELETE SET NULL
);

-- ─── LOGISTICS ──────────────────────────────────────────────

CREATE TABLE materials (
  id          VARCHAR(26)   NOT NULL PRIMARY KEY,
  name        VARCHAR(150)  NOT NULL,
  unit        VARCHAR(20)   NOT NULL,
  cost_price  DECIMAL(12,2) NOT NULL,
  sell_price  DECIMAL(12,2) NOT NULL,
  stock       INT           NOT NULL DEFAULT 0,
  min_stock   INT           NOT NULL DEFAULT 0,
  category    VARCHAR(50)   NULL,
  created_at  TIMESTAMP     NULL,
  updated_at  TIMESTAMP     NULL
);

CREATE TABLE project_materials (
  id            VARCHAR(26) NOT NULL PRIMARY KEY,
  project_id    VARCHAR(26) NOT NULL,
  material_id   VARCHAR(26) NOT NULL,
  qty_planned   INT         NOT NULL DEFAULT 0,
  qty_used      INT         NOT NULL DEFAULT 0,
  created_at    TIMESTAMP   NULL,
  FOREIGN KEY (project_id)  REFERENCES projects(id)  ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
);

-- Aset dengan cicilan (dari data nyata: mobil pickup, scross, laptop, kursi)
CREATE TABLE assets (
  id              VARCHAR(26)   NOT NULL PRIMARY KEY,
  name            VARCHAR(150)  NOT NULL,
  category        VARCHAR(50)   NOT NULL,
  purchase_date   DATE          NOT NULL,
  value           DECIMAL(15,2) NOT NULL,
  condition       ENUM('GOOD','FAIR','DAMAGED') NOT NULL DEFAULT 'GOOD',
  location        VARCHAR(100)  NULL,
  has_installment TINYINT(1)    NOT NULL DEFAULT 0,   -- apakah ada cicilan
  total_install   DECIMAL(15,2) NULL,                 -- total cicilan
  paid_install    DECIMAL(15,2) NOT NULL DEFAULT 0,   -- sudah dibayar
  notes           TEXT          NULL,
  created_at      TIMESTAMP     NULL,
  updated_at      TIMESTAMP     NULL
);

-- ─── NOTIFICATIONS ──────────────────────────────────────────

CREATE TABLE notifications (
  id          VARCHAR(26)  NOT NULL PRIMARY KEY,
  user_id     VARCHAR(26)  NOT NULL,
  type        VARCHAR(50)  NOT NULL,
  title       VARCHAR(150) NOT NULL,
  message     TEXT         NOT NULL,
  is_read     TINYINT(1)   NOT NULL DEFAULT 0,
  metadata    JSON         NULL,
  created_at  TIMESTAMP    NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ─── AUDIT LOGS ─────────────────────────────────────────────

CREATE TABLE audit_logs (
  id           VARCHAR(26)  NOT NULL PRIMARY KEY,
  user_id      VARCHAR(26)  NULL,
  action       VARCHAR(100) NOT NULL,
  model_type   VARCHAR(50)  NOT NULL,
  model_id     VARCHAR(26)  NOT NULL,
  old_values   JSON         NULL,
  new_values   JSON         NULL,
  ip_address   VARCHAR(45)  NULL,
  created_at   TIMESTAMP    NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
