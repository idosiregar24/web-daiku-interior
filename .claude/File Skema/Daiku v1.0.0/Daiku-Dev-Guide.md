# Daiku Interior — Developer Guide
**Version:** v1.0.0 — Initial Release  
**Status:** Draft · Pre-Revision  
**Prepared by:** Ido Refael Siregar  
**Last Updated:** Agustus 2026  

---
## Estimasi Biaya, Rencana Testing, Struktur Code & AI Setup

---

## 1. ESTIMASI BIAYA DEVELOPMENT

### 1.1 Asumsi Dasar

| Parameter | Detail |
|---|---|
| Tim | 3 developer (Kamu/Lead + Dev A + Dev B) |
| Durasi | 13 minggu (~3 bulan) |
| Jam kerja/hari | 4 jam/orang |
| Total jam efektif | 3 × 4 × 65 hari = **780 jam** |

---

### 1.2 Biaya SDM (Skenario Freelance / Kontrak)

#### Opsi A: Semua Freelance (termasuk kamu)

| Role | Rate/jam | Total Jam | Total Biaya |
|---|---|---|---|
| Kamu (Lead + Arch) | Rp 150.000 | 260 jam | Rp 39.000.000 |
| Dev A (Mid Fullstack) | Rp 100.000 | 260 jam | Rp 26.000.000 |
| Dev B (Mid Fullstack) | Rp 100.000 | 260 jam | Rp 26.000.000 |
| **Total SDM** | | | **Rp 91.000.000** |

#### Opsi B: Kamu In-House, 2 Dev Freelance

| Role | Rate/jam | Total Jam | Total Biaya |
|---|---|---|---|
| Kamu (Lead, gaji existing) | — | — | — |
| Dev A (Freelance) | Rp 100.000 | 260 jam | Rp 26.000.000 |
| Dev B (Freelance) | Rp 100.000 | 260 jam | Rp 26.000.000 |
| **Total SDM** | | | **Rp 52.000.000** |

> **Catatan:** Rate Rp 100.000–150.000/jam adalah rate mid-level freelance Indonesia 2024. Sesuaikan dengan negosiasi tim.

---

### 1.3 Biaya Infrastruktur (Per Bulan → Per Tahun)

#### Server & Hosting

| Komponen | Spesifikasi | Harga/bulan | Harga/tahun |
|---|---|---|---|
| VPS Production | 4 vCPU, 8GB RAM, 100GB SSD | Rp 400.000 | Rp 4.800.000 |
| VPS Staging | 2 vCPU, 4GB RAM, 50GB SSD | Rp 200.000 | Rp 2.400.000 |
| Domain (.com) | daiku-interior.com | Rp 150.000 | Rp 150.000/thn |
| SSL Certificate | Let's Encrypt | **Gratis** | — |
| Backup Storage | 50GB object storage | Rp 50.000 | Rp 600.000 |
| **Total Infra/tahun** | | | **Rp 7.950.000** |

> **Rekomendasi Provider Indonesia:** IDCloudHost, Biznet Gio, atau DigitalOcean Singapore (latency rendah untuk user Indonesia).

#### Software & Tools

| Tool | Biaya |
|---|---|
| GitHub (Team plan, 3 users) | $4/user/bulan = ~Rp 600.000/bulan |
| Notion (Plus plan, team) | $8/user/bulan atau beli annual |
| Figma (jika perlu mockup) | Free tier cukup untuk 3 dev |
| **Total Tools/bulan** | ~Rp 700.000 |

---

### 1.4 Ringkasan Total Biaya

#### Skenario Realistis (Opsi B: 2 Dev Freelance)

| Kategori | Biaya |
|---|---|
| SDM Development (3 bulan) | Rp 52.000.000 |
| Infrastruktur (3 bulan dev + 1 tahun prod) | Rp 9.950.000 |
| Tools & Software (3 bulan) | Rp 2.100.000 |
| Contingency 10% | Rp 6.400.000 |
| **TOTAL ESTIMASI** | **Rp 70.450.000** |

#### Skenario Lengkap (Opsi A: Semua Freelance)

| Kategori | Biaya |
|---|---|
| SDM Development (3 bulan) | Rp 91.000.000 |
| Infrastruktur + Tools | Rp 12.050.000 |
| Contingency 10% | Rp 10.300.000 |
| **TOTAL ESTIMASI** | **Rp 113.350.000** |

---

### 1.5 Biaya Maintenance Post Go-Live (Per Bulan)

| Item | Estimasi |
|---|---|
| Server (production only) | Rp 400.000 |
| Backup storage | Rp 50.000 |
| Bug fix / minor update (5 jam/bulan) | Rp 500.000–750.000 |
| **Total/bulan** | **Rp 950.000–1.200.000** |

---

## 2. RENCANA TESTING

### 2.1 Strategi Testing (3 Layer)

```
Layer 1: Unit Test       → Logic bisnis terisolasi
Layer 2: Feature Test    → HTTP request → response (controller + DB)
Layer 3: UAT             → Manual, dilakukan oleh user nyata Daiku
```

**Tools:** Pest PHP (Laravel testing framework modern, syntax lebih bersih dari PHPUnit)

---

### 2.2 Unit Tests — Prioritas Tinggi

#### PenaltyService
```php
it('generates Rp50.000 penalty when daily form not submitted', function () {
    $staff = User::factory()->fieldStaff()->create();
    $task  = Task::factory()->activeToday()->for($staff)->create();

    // Tidak ada DailyTaskForm untuk hari ini
    $result = app(PenaltyService::class)->checkAndPenalize($staff, today());

    expect($result)->toHaveCount(1)
        ->and($result[0]->amount)->toBe(50000)
        ->and($result[0]->type)->toBe('DAILY_FORM_MISSING');
});

it('does not penalize if daily form already submitted', function () {
    $staff = User::factory()->fieldStaff()->create();
    $task  = Task::factory()->activeToday()->for($staff)->create();
    DailyTaskForm::factory()->for($task)->submittedToday()->create();

    $result = app(PenaltyService::class)->checkAndPenalize($staff, today());

    expect($result)->toBeEmpty();
});
```

#### TerminService
```php
it('always schedules termin on Saturday', function () {
    // Test dari berbagai hari dalam seminggu
    foreach (range(0, 6) as $dayOffset) {
        $inputDate = now()->startOfWeek()->addDays($dayOffset);
        $saturday  = app(TerminService::class)->getNextSaturday($inputDate);

        expect($saturday->dayOfWeek)->toBe(Carbon::SATURDAY);
    }
});

it('validates termin percentages sum to 100', function () {
    $project = Project::factory()->create(['contract_value' => 10000000]);

    expect(fn() => app(TerminService::class)->create($project, [
        ['percentage' => 30, 'scheduled_date' => now()],
        ['percentage' => 50, 'scheduled_date' => now()->addWeeks(4)],
        // Total 80%, harus error
    ]))->toThrow(ValidationException::class);
});
```

#### OvertimeApproval
```php
it('moves overtime to PENDING_FINANCE after PM approval', function () {
    $overtime = OvertimeRequest::factory()->pendingPM()->create();
    $pm = User::factory()->pm()->create();

    app(OvertimeService::class)->approveByPM($overtime, $pm);

    expect($overtime->fresh()->status)->toBe('PENDING_FINANCE');
});
```

---

### 2.3 Feature Tests — Alur Utama

#### Alur Presales
```php
it('converts lead to active project after deal confirmation', function () {
    // 1. Buat lead
    $lead = Lead::factory()->create(['status' => 'NEW']);

    // 2. Update ke DEAL
    $this->actingAs(marketingUser())
        ->patch("/crm/leads/{$lead->id}/status", ['status' => 'DEAL'])
        ->assertRedirect();

    // 3. Verifikasi project terbuat otomatis
    expect(Project::where('lead_id', $lead->id)->exists())->toBeTrue();
    expect($lead->fresh()->status)->toBe('DEAL');
});
```

#### Task Lock Mechanism
```php
it('prevents field staff from editing task content', function () {
    $staff = User::factory()->fieldStaff()->create();
    $task  = Task::factory()->assignedTo($staff)->create(['is_locked' => true]);

    $this->actingAs($staff)
        ->patch("/tasks/{$task->id}", ['title' => 'Judul baru'])
        ->assertForbidden();
});

it('allows field staff to update task status only', function () {
    $staff = User::factory()->fieldStaff()->create();
    $task  = Task::factory()->assignedTo($staff)->create();

    $this->actingAs($staff)
        ->patch("/tasks/{$task->id}/status", ['status' => 'IN_PROGRESS'])
        ->assertOk();

    expect($task->fresh()->status)->toBe('IN_PROGRESS');
});
```

#### QA Blocking
```php
it('blocks next milestone if QA not approved', function () {
    $project   = Project::factory()->withMilestones(2)->create();
    $milestone = $project->milestones->first();
    $nextMs    = $project->milestones->last();

    // QA masih PENDING
    QAForm::factory()->for($milestone)->pending()->create();

    $this->actingAs(pmUser())
        ->patch("/milestones/{$nextMs->id}/start")
        ->assertForbidden();
});
```

#### RBAC
```php
dataset('unauthorized_roles_for_quotation_approval', [
    'marketing', 'designer', 'qa', 'logistics', 'field_staff'
]);

it('blocks unauthorized roles from approving quotation', function (string $role) {
    $quotation = Quotation::factory()->submitted()->create();
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)
        ->patch("/quotations/{$quotation->id}/approve")
        ->assertForbidden();
})->with('unauthorized_roles_for_quotation_approval');
```

---

### 2.4 Jadwal Testing

| Fase | Minggu | Aktivitas | PIC |
|---|---|---|---|
| Unit Test Foundation | Minggu 5–6 | Test PenaltyService, TerminService | Dev A |
| Unit Test Lanjutan | Minggu 7–8 | Test OvertimeService, QABlocking | Dev B |
| Feature Test Presales | Minggu 9 | Lead→Design→Quotation→Deal | Kamu |
| Feature Test Execution | Minggu 9–10 | Project→Task→QA→Termin | Dev A |
| Feature Test Finance/Logistics | Minggu 10 | Finance, Logistics, Notifikasi | Dev B |
| RBAC Test Menyeluruh | Minggu 10 | Semua role × semua route | Kamu |
| UAT Sesi 1 | Minggu 11 | Marketing + Designer | Kamu |
| UAT Sesi 2 | Minggu 11 | PM + Field Staff | Kamu |
| UAT Sesi 3 | Minggu 11 | QA + Finance | Kamu |
| UAT Sesi 4 | Minggu 11 | CEO + Logistics | Kamu |
| Regression Test | Minggu 12 | Setelah semua bug fix | Tim |

---

### 2.5 Coverage Target

| Layer | Target |
|---|---|
| Unit Test (Services, Jobs) | ≥ 80% |
| Feature Test (Controllers) | ≥ 70% |
| RBAC Routes | 100% (semua route dicek) |
| Critical paths (Penalty, QA Block, Termin) | 100% |

---

### 2.6 UAT Checklist per Divisi

#### Marketing
- [ ] Bisa buat lead baru
- [ ] Bisa update pipeline status
- [ ] Notifikasi follow-up muncul tepat waktu
- [ ] Tidak bisa akses modul Finance/QA

#### Field Staff / Tukang
- [ ] Hanya lihat task milik sendiri
- [ ] Tidak bisa edit judul/deskripsi task
- [ ] Bisa update status task
- [ ] Form daily muncul dan bisa disubmit
- [ ] Bisa ajukan lembur
- [ ] Menerima notifikasi penalti jika form tidak diisi

#### Finance
- [ ] Lihat semua transaksi + filter
- [ ] Bisa update status termin
- [ ] Generate invoice PDF
- [ ] Lihat dana family gathering + riwayat
- [ ] Approve/reject overtime dari PM
- [ ] Export Excel cash flow

#### CEO
- [ ] Semua widget dashboard terisi data
- [ ] Bisa akses semua modul
- [ ] Grafik pipeline, revenue, cash flow akurat

---

## 3. STRUKTUR CODE DETAIL

### 3.1 Konvensi Penamaan

```
Controllers  : PascalCase, suffix Controller     → LeadController
Models       : PascalCase, singular              → Lead, Project, QaForm
Services     : PascalCase, suffix Service        → PenaltyService
Jobs         : PascalCase, suffix Job            → DailyPenaltyJob
Events       : PascalCase, past tense            → OvertimeApproved
Listeners    : PascalCase, descriptive           → SendOvertimeNotification
Requests     : PascalCase, suffix Request        → StoreLeadRequest
Resources    : PascalCase, suffix Resource       → LeadResource
Pages (React): PascalCase, folder/file           → CRM/LeadIndex.tsx
Components   : PascalCase                        → StatusBadge.tsx
Migrations   : snake_case timestamp              → 2025_01_06_create_leads_table
```

### 3.2 Service Layer Pattern

Semua business logic **wajib** di Service, bukan di Controller.

```php
// ✅ BENAR — Controller thin
class LeadController extends Controller
{
    public function store(StoreLeadRequest $request, LeadService $service)
    {
        $lead = $service->create($request->validated());
        return redirect()->route('crm.leads.index')
            ->with('success', 'Lead berhasil ditambahkan.');
    }
}

// ✅ BENAR — Service fat
class LeadService
{
    public function create(array $data): Lead
    {
        $lead = Lead::create($data);
        PipelineLog::create([
            'lead_id'    => $lead->id,
            'from_status' => null,
            'to_status'  => 'NEW',
            'changed_by' => auth()->id(),
        ]);
        // Trigger notification if followUpDate is today
        if ($lead->follow_up_date?->isToday()) {
            $lead->assignedTo?->notify(new FollowUpReminderNotification($lead));
        }
        return $lead;
    }
}
```

### 3.3 Inertia Page Pattern

```tsx
// resources/js/Pages/CRM/LeadIndex.tsx
import { Head, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import { LeadTable } from '@/Components/modules/crm/LeadTable'
import { PageHeader } from '@/Components/shared/PageHeader'
import type { Lead, PaginatedResponse } from '@/types'

interface Props {
  leads: PaginatedResponse<Lead>
  filters: { status?: string; priority?: string }
}

export default function LeadIndex({ leads, filters }: Props) {
  return (
    <AppLayout>
      <Head title="Manajemen Lead" />
      <PageHeader
        title="Manajemen Lead"
        action={{ label: 'Tambah Lead', href: route('crm.leads.create') }}
      />
      <LeadTable leads={leads} filters={filters} />
    </AppLayout>
  )
}
```

### 3.4 Shared Types (TypeScript)

```typescript
// resources/js/types/index.ts

export type Role =
  | 'CEO' | 'MARKETING' | 'DESIGNER' | 'ESTIMATOR'
  | 'PROJECT_MANAGER' | 'QA' | 'FINANCE' | 'LOGISTICS' | 'FIELD_STAFF'

export type LeadStatus =
  | 'NEW' | 'CONTACTED' | 'BRIEFING' | 'DESIGN_PHASE'
  | 'OFFERED' | 'DEAL' | 'LOST'

export interface User {
  id: string
  name: string
  email: string
  role: Role
}

export interface Lead {
  id: string
  client_name: string
  contact: string
  priority: 'HIGH' | 'MEDIUM' | 'LOW'
  status: LeadStatus
  follow_up_date: string | null
  assigned_to: User | null
}

export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
```

---

## 4. FILE `.claude` — SETUP AI ASSISTANT

### 4.1 Struktur File `.claude`

Buat file ini di root project untuk konteks AI yang konsisten di semua sesi coding.

```
daiku-interior/
├── .claude/
│   ├── CLAUDE.md              ← Konteks utama project
│   ├── commands/
│   │   ├── new-module.md      ← Generate modul baru
│   │   ├── new-migration.md   ← Generate migration
│   │   └── write-test.md      ← Generate test
│   └── context/
│       ├── stack.md           ← Stack reference
│       └── patterns.md        ← Code patterns wajib
```

### 4.2 File `CLAUDE.md` (Root Context)

```markdown
# Daiku Interior — AI Context

## Project Overview
Internal ERP/Project Management system untuk perusahaan interior Daiku Interior.
Web app dengan Laravel 11 + Inertia.js + React + TypeScript.

## Tech Stack
- Backend: Laravel 11, PHP 8.3, Eloquent ORM, PostgreSQL 16
- Frontend: React 18, Inertia.js v2, TypeScript, Tailwind CSS, shadcn/ui
- Cache/Queue: Redis, Laravel Queue + Horizon
- Real-time: Laravel Echo + Soketi
- Testing: Pest PHP
- Export: DomPDF (PDF), Laravel Excel (xlsx)

## Roles (9 role)
CEO, MARKETING, DESIGNER, ESTIMATOR, PROJECT_MANAGER, QA, FINANCE, LOGISTICS, FIELD_STAFF

## Key Business Rules
1. Tukang (FIELD_STAFF) tidak bisa edit task — hanya update status
2. Form daily task wajib diisi sebelum 21:00, jika tidak = penalti Rp50.000 otomatis
3. Dana penalti masuk ke FamilyGatheringFund (pos keuangan khusus)
4. Termin selalu dijadwalkan pada hari Sabtu terdekat setelah milestone selesai
5. Milestone berikutnya LOCKED sampai QA Form milestone sebelumnya APPROVED
6. Quotation approval: CEO dulu → baru PM (sequential)
7. Lembur: Tukang ajukan → PM approve → Finance approve → dibayar

## Code Conventions
- Semua business logic di Service class, Controller harus thin
- Gunakan Form Request untuk validasi (bukan validate() di controller)
- Semua model wajib punya PHPDoc properties
- React component wajib ada TypeScript interface untuk props
- Gunakan Inertia shared data untuk user & notifikasi (via HandleInertiaRequests)

## Folder Key Paths
- Controllers: app/Http/Controllers/{Module}/
- Services: app/Services/
- Models: app/Models/
- Jobs: app/Jobs/
- React Pages: resources/js/Pages/{Module}/
- Components: resources/js/Components/
- Types: resources/js/types/index.ts
- Migrations: database/migrations/

## Naming Conventions
- Controllers: PascalCase + Controller suffix
- Services: PascalCase + Service suffix
- Jobs: PascalCase + Job suffix
- React Pages: PascalCase, match controller name
- DB tables: snake_case plural

## Do NOT
- Jangan taruh logic di Controller — pindahkan ke Service
- Jangan query di View/Page component — semua data dari Controller via Inertia props
- Jangan gunakan raw SQL kecuali sangat perlu — gunakan Eloquent
- Jangan lupa tambahkan RBAC middleware di setiap route baru
```

### 4.3 File `.claude/commands/new-module.md`

```markdown
# Command: Generate Module Baru

Saat saya minta "buat modul [NamaModul]", generate file-file berikut:

1. **Migration** — `database/migrations/YYYY_MM_DD_create_{table}_table.php`
   - Sertakan semua kolom sesuai skema PRD
   - Tambahkan foreign key constraint
   - Gunakan $table->id() dan timestamps()

2. **Model** — `app/Models/{Name}.php`
   - PHPDoc @property untuk semua kolom
   - Definisi $fillable
   - Relasi Eloquent (belongsTo, hasMany, dll)
   - Scope query jika relevan (scopeActive, scopeOverdue)

3. **Service** — `app/Services/{Name}Service.php`
   - Method CRUD dasar
   - Business logic sesuai PRD
   - Event dispatch jika ada trigger notifikasi

4. **Controller** — `app/Http/Controllers/{Module}/{Name}Controller.php`
   - Resource controller (index, show, store, update, destroy)
   - Inject Service via constructor
   - Return Inertia::render() untuk GET, redirect() untuk POST/PATCH/DELETE

5. **Form Requests** — `app/Http/Requests/Store{Name}Request.php`
   - Rules validasi lengkap
   - authorize() cek role

6. **React Page** — `resources/js/Pages/{Module}/{Name}Index.tsx`
   - Interface Props dengan data dari controller
   - Gunakan AppLayout
   - Gunakan PageHeader component

7. **Route** — tambahkan ke `routes/web.php`
   - Group dengan middleware auth + role
   - Resource route atau manual sesuai kebutuhan

Contoh: "buat modul Overtime" → generate semua 7 file di atas untuk fitur overtime.
```

### 4.4 File `.claude/context/patterns.md`

```markdown
# Patterns Wajib — Daiku Interior

## Pattern 1: Notifikasi Trigger
Setiap kali ada event penting, dispatch Event dan buat Listener yang kirim notifikasi:

```php
// Di Service
event(new OvertimeApproved($overtime));

// Listener
class SendOvertimeApprovedNotification
{
    public function handle(OvertimeApproved $event): void
    {
        $event->overtime->staff->notify(
            new OvertimeStatusNotification($event->overtime, 'approved_pm')
        );
        // Notify Finance juga
        User::finance()->each(fn($u) =>
            $u->notify(new OvertimeReadyForFinanceNotification($event->overtime))
        );
    }
}
```

## Pattern 2: RBAC di Route
```php
Route::middleware(['auth', 'role:PROJECT_MANAGER,CEO'])->group(function () {
    Route::resource('milestones', MilestoneController::class);
});
```

## Pattern 3: Scheduled Job
```php
// app/Console/Kernel.php atau routes/console.php (Laravel 11)
Schedule::job(DailyPenaltyJob::class)->dailyAt('21:00')->weekdays();
Schedule::job(SaturdayTerminReminderJob::class)->weekly()->saturdays()->at('08:00');
```

## Pattern 4: Inertia Shared Data
```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => [
            'user' => $request->user()?->only('id','name','email','role'),
        ],
        'notifications' => fn() => $request->user()?->unreadNotifications
            ->take(10)
            ->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'created_at' => $n->created_at->diffForHumans(),
            ]),
        'flash' => [
            'success' => $request->session()->get('success'),
            'error'   => $request->session()->get('error'),
        ],
    ];
}
```
```

---

## 5. SKILL & TOOL AI YANG HARUS DIPASANG

### 5.1 Untuk Claude (claude.ai Projects)

Buat satu **Claude Project** khusus "Daiku Interior Dev" dan upload konteks berikut ke Project Knowledge:

| File yang di-upload | Tujuan |
|---|---|
| `PRD-Daiku-Interior-System.md` | Referensi spesifikasi lengkap |
| `.claude/CLAUDE.md` | Konteks stack & konvensi |
| `.claude/context/patterns.md` | Pattern code yang harus diikuti |
| `database/migrations/*.php` (setelah dibuat) | Agar Claude tahu struktur tabel terkini |
| `app/Models/*.php` (setelah dibuat) | Agar Claude tahu relasi model |

**Cara pakai di sesi coding:**
```
"Buatkan TaskController dengan pattern yang ada di CLAUDE.md.
Task hanya bisa di-edit PM, tukang hanya update status.
Gunakan TaskService dan StoreTaskRequest."
```

### 5.2 Ekstensi VS Code yang Wajib Dipasang

```
Laravel:
  - Laravel Extra Intellisense (amiralizadeh9480)
  - PHP Intelephense (bmewburn) — WAJIB
  - Laravel Blade Snippets
  - Laravel Artisan (ryannaddy)

React + TypeScript:
  - ES7+ React/Redux/React-Native snippets
  - TypeScript Importer
  - Tailwind CSS IntelliSense — WAJIB

Database:
  - Database Client (cweijan) — lihat PostgreSQL langsung di VS Code

Git & Productivity:
  - GitLens
  - Error Lens
  - Todo Tree

AI Coding:
  - GitHub Copilot (jika budget ada)
  - atau Codeium (gratis)
```

### 5.3 Laravel-Specific Setup Awal

```bash
# Wajib install setelah clone repo
composer require spatie/laravel-permission        # RBAC
composer require laravel/telescope --dev           # Debugging
composer require laravel/horizon                   # Queue monitoring
composer require barryvdh/laravel-dompdf           # PDF export
composer require maatwebsite/excel                 # Excel export
composer require tightenco/ziggy                   # Route helper di React

npm install @inertiajs/react                       # Inertia React adapter
npm install @tanstack/react-table                  # Data table
npm install react-hook-form zod @hookform/resolvers # Form + validation
npm install recharts                               # Charts
npm install date-fns                               # Date utilities
npm install laravel-echo pusher-js                 # Real-time

# Setup Pest PHP untuk testing
composer require pestphp/pest --dev
composer require pestphp/pest-plugin-laravel --dev
php artisan pest:install
```

### 5.4 GitHub Actions CI/CD (`.github/workflows/deploy.yml`)

```yaml
name: Deploy Daiku

on:
  push:
    branches: [main, develop]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - name: Install dependencies
        run: composer install --no-dev
      - name: Run Pest tests
        run: php artisan test --parallel

  deploy-staging:
    needs: test
    if: github.ref == 'refs/heads/develop'
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to Staging
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.STAGING_HOST }}
          username: ${{ secrets.SSH_USER }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd /var/www/daiku-staging
            git pull origin develop
            composer install --no-dev
            php artisan migrate --force
            php artisan optimize
            npm ci && npm run build

  deploy-production:
    needs: test
    if: github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to Production
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.PROD_HOST }}
          username: ${{ secrets.SSH_USER }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd /var/www/daiku-production
            git pull origin main
            composer install --no-dev --optimize-autoloader
            php artisan migrate --force
            php artisan optimize
            php artisan queue:restart
            npm ci && npm run build
```

---

## 6. RINGKASAN KEPUTUSAN AKHIR

| Aspek | Keputusan |
|---|---|
| **Stack** | Laravel 11 + Inertia.js v2 + React 18 + TypeScript |
| **Database** | PostgreSQL 16 |
| **Cache/Queue** | Redis + Laravel Queue + Horizon |
| **Real-time** | Laravel Echo + Soketi (self-hosted) |
| **Auth/RBAC** | Laravel Breeze + Spatie Permission |
| **PDF** | DomPDF via Laravel |
| **Excel** | Laravel Excel (Maatwebsite) |
| **Testing** | Pest PHP |
| **CI/CD** | GitHub Actions |
| **Tim** | 3 orang part-time (4 jam/hari) |
| **Durasi** | 13 minggu (12 aktif + 1 buffer) |
| **Estimasi Biaya** | Rp 52–91 juta (tergantung model SDM) |
| **Biaya Infra/tahun** | ~Rp 8 juta |

---
---

## 7. DOCUMENT CHANGELOG

| Versi | Tanggal | Status | Penulis | Deskripsi |
|---|---|---|---|---|
| v1.0.0 | Agustus 2026 | Initial Release | Ido Refael Siregar | Dokumen pertama developer guide. Mencakup rekomendasi stack final (Laravel + Inertia + React), estimasi biaya, rencana testing, struktur kode, setup `.claude`, dan CI/CD pipeline. |

### Konvensi Versi Dokumen
| Kode | Artinya |
|---|---|
| v1.0.0 | Initial Release — versi pertama, belum ada revisi |
| v1.1.0 | Minor Revision — penambahan/perubahan fitur atau klarifikasi |
| v1.0.1 | Patch — koreksi typo, data salah, atau perbaikan kecil |
| v2.0.0 | Major Revision — perubahan arsitektur atau stack signifikan |

*Daiku Interior Enterprise System — Developer Guide v1.0.0 — Initial Release*  
*Confidential — Internal Use Only*
