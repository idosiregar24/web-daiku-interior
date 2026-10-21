<?php

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\SiteFindingStatus;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\TaskStatus;
use App\Enums\TerminStatus;
use App\Enums\WorkingDrawingStatus;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BudgetLine;
use App\Models\BudgetOverrunRequest;
use App\Models\City;
use App\Models\DailyTaskForm;
use App\Models\Design;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadCategory;
use App\Models\LeadFollowUp;
use App\Models\LeadSource;
use App\Models\LeadSurvey;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\SiteFinding;
use App\Models\SupervisorPayment;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\TerminService;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * .claude/plan/simulasi-alur-klien.md, end to end over HTTP — one client
 * (Rina Kusuma, kafe 2 lantai) from the first contact to the pelunasan,
 * each step as the role the document says logs in, with the document's
 * own inputs. Step numbers in the comments are the document's "Langkah".
 *
 * Variations (PM returns, client rejects, survey outside Pekanbaru,
 * payment rejected, QA rejected) are covered by their own tests:
 * PresalesIntegrationTest, SurveySchedulingTest, InvoiceTest, QaFormTest.
 */

beforeEach(function () {
    // Monday, a working day.
    Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(RoleSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

function journeyUser(string $role, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole(User::rolesFor($role));

    return $user;
}

function journeyNotified(User $user, string $type): int
{
    return Notification::where('user_id', $user->id)->where('type', $type)->count();
}

function journeyAt(string $date, string $time = '09:00:00'): void
{
    Carbon::setTestNow(Carbon::parse("{$date} {$time}", 'Asia/Jakarta'));
}

/** Marketing sends the proof of a transfer; Finance verifies it into BCA 5835 (Langkah 11–12, 20, 27). */
function journeyPay(object $test, Invoice $invoice): void
{
    $test->actingAs($test->marketing)->post(route('finance.invoices.proof', $invoice), [
        'payment_proof_url' => 'https://drive.google.com/contoh-bukti-transfer-rina',
    ])->assertSessionHasNoErrors();
    expect($invoice->fresh()->status->value)->toBe('MENUNGGU_VERIFIKASI');

    $test->actingAs($test->finance)->post(route('finance.invoices.verify', $invoice), [
        'bank_account_id' => $test->bca->id,
        'paid_date' => now('Asia/Jakarta')->toDateString(),
    ])->assertSessionHasNoErrors();
    expect($invoice->fresh()->status->value)->toBe('TERVERIFIKASI');
}

test('simulasi alur klien: Rina Kusuma from the first contact to the pelunasan', function () {
    $this->marketing = journeyUser('MARKETING', 'Marketing Daiku Interior');
    $estimator = journeyUser('ESTIMATOR', 'Estimator Daiku Interior');
    $pm = journeyUser('PM', 'PM Daiku Interior');
    $asistenPm = journeyUser('ASISTEN_PM', 'Asisten PM Daiku Interior');
    $ceo = journeyUser('CEO', 'CEO Daiku Interior');
    $this->finance = journeyUser('FINANCE', 'Finance Daiku Interior');
    $head = journeyUser('KEPALA_DESAIN', 'Kepala Desain Daiku Interior');
    $architect = journeyUser('DESIGNER', 'Designer Daiku Interior');
    $tukang = journeyUser('FIELD_STAFF', 'Field Staff Daiku Interior');
    $qa = journeyUser('QA', 'QA Daiku Interior');
    $logistics = journeyUser('LOGISTICS', 'Logistik Daiku Interior');
    $this->bca = BankAccount::factory()->create(['bank_name' => 'BCA', 'account_no' => '5835', 'label' => 'BCA 5835', 'is_active' => true]);
    $pekanbaru = City::firstOrCreate(['name' => 'Pekanbaru'], ['province' => 'Riau']);

    // ── Tahap 1 — Lead masuk & follow-up ─────────────────────────────────

    // Langkah 1 · Marketing menambah lead.
    $this->actingAs($this->marketing)->post(route('crm.leads.store'), [
        'client_name' => 'Rina Kusuma',
        'phone' => '081266778899',
        'city_id' => $pekanbaru->id,
        'address' => 'Jl. Tuanku Tambusai No. 45, Pekanbaru',
        'maps_url' => 'https://maps.app.goo.gl/contohKafeRina',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'lead_category_id' => LeadCategory::findOrCreateByName('KOMERSIAL')->id,
        'service' => 'Interior Kafe',
        'priority' => 'HOT',
        'order_detail' => 'Interior kafe 2 lantai, luas ±120 m², konsep industrial hangat, butuh bar, area duduk 40 kursi dan mural dinding.',
        'first_contacted_at' => '2026-10-19',
        'assigned_to' => $this->marketing->id,
    ])->assertSessionHasNoErrors();

    $lead = Lead::where('client_name', 'Rina Kusuma')->sole();
    expect($lead->status)->toBe(LeadStatus::FollowUp)
        ->and($lead->phone)->toBe('081266778899');
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $lead))->assertOk();

    // Langkah 2 · follow-up FU-1 dijadwalkan besok, lalu selesai.
    $this->actingAs($this->marketing)->post(route('crm.follow-ups.store', $lead), ['scheduled_date' => '2026-10-20'])
        ->assertSessionHasNoErrors();
    $followUp = LeadFollowUp::where('lead_id', $lead->id)->sole();
    expect($followUp->sequence)->toBe(1);
    $this->actingAs($this->marketing)->post(route('crm.follow-ups.complete', $followUp), [
        'result_note' => 'Klien tertarik, minta survey lokasi minggu ini.',
    ])->assertSessionHasNoErrors();
    expect($followUp->fresh()->done_at)->not->toBeNull();

    // ── Tahap 2 — Survey lokasi (dalam Pekanbaru = gratis) ───────────────

    // Langkah 3 · Ajukan Desain/Survey → Jadwalkan Survey, alamat lead.
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $lead), [
        'type' => 'SURVEY',
        'scheduled_at' => '2026-10-21 10:00',
        'is_outside_pekanbaru' => false,
    ])->assertSessionHasNoErrors();

    $survey = LeadSurvey::where('lead_id', $lead->id)->sole();
    expect($lead->fresh()->status)->toBe(LeadStatus::DealDesain)
        ->and($survey->address)->toBe('Jl. Tuanku Tambusai No. 45, Pekanbaru');

    // Langkah 4 · Tandai Survey Selesai.
    $this->actingAs($this->marketing)->post(route('crm.surveys.complete', $survey), [
        'result_note' => 'Ruang 2 lantai, plafon 3,2 m, listrik perlu tambah daya. Klien setuju lanjut ke desain.',
    ])->assertSessionHasNoErrors();
    expect($survey->fresh()->status->value)->toBe('SELESAI');

    // ── Tahap 3 — RAB Jasa Desain ────────────────────────────────────────

    // Langkah 5 · Buat RAB → RAB Jasa Desain, with a link and a site photo.
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $lead), [
        'type' => 'RAB_DESAIN',
        'note' => 'Desain interior kafe 2 lantai ±120 m² + render 3D. Klien minta 2x revisi.',
        'reference_links' => ['https://pinterest.com/contoh-kafe-industrial'],
        'reference_photos' => [UploadedFile::fake()->image('lokasi.jpg', 800, 600)],
    ])->assertSessionHasNoErrors();

    $designRab = Quotation::where('lead_id', $lead->id)->where('type', 'DESAIN')->sole();
    expect($designRab->status)->toBe(QuotationStatus::Diminta)
        ->and($designRab->references()->count())->toBe(2)
        ->and(journeyNotified($estimator, 'quotation_requested'))->toBe(1);

    // Langkah 6 · Estimator: Mulai Susun RAB → Simpan sebagai Draft → skema → catatan → Kirim ke PM.
    $this->actingAs($estimator)->post(route('quotations.start', $designRab))->assertSessionHasNoErrors();
    $this->actingAs($estimator)->get(route('quotations.show', $designRab))->assertOk();
    $this->actingAs($estimator)->put(route('quotations.items.update', $designRab), [
        'sections' => [[
            'name' => 'Jasa Desain',
            'items' => [['description' => 'Jasa desain interior + render 3D', 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 4_500_000]],
        ]],
    ])->assertSessionHasNoErrors();

    $terms = $designRab->paymentTerms()->get();
    expect($terms)->toHaveCount(1)
        ->and((float) $terms[0]->percentage)->toBe(100.0)
        ->and($terms[0]->trigger->value)->toBe('DI_MUKA');
    $this->actingAs($estimator)->put(route('quotations.paymentTerms.update', $designRab), [
        'terms' => [['label' => $terms[0]->label, 'percentage' => 100, 'trigger' => 'DI_MUKA']],
    ])->assertSessionHasNoErrors();
    $this->actingAs($estimator)->put(route('quotations.clientNotes.update', $designRab), [
        'client_notes' => 'Termasuk 2x revisi desain.',
    ])->assertSessionHasNoErrors();
    $this->actingAs($estimator)->post(route('quotations.submit', $designRab))->assertSessionHasNoErrors();
    expect($designRab->fresh()->status)->toBe(QuotationStatus::Submitted);

    // Langkah 7 · PM: ✔ every item in the RAB table → Setujui RAB → straight to Marketing.
    $this->actingAs($pm)->post(route('quotations.review', $designRab), [
        'decision' => 'approve',
        'items' => $designRab->items()->get()->map(fn ($item) => ['item_id' => $item->id, 'verdict' => 'OK'])->all(),
    ])->assertSessionHasNoErrors();
    expect($designRab->fresh()->status)->toBe(QuotationStatus::ReadyToSend)
        ->and(journeyNotified($this->marketing, 'quotation_ready_to_send'))->toBe(1);

    // Langkah 9 · Marketing: Kirim ke Klien + Unduh PDF.
    $this->actingAs($this->marketing)->post(route('quotations.sendToClient', $designRab))->assertSessionHasNoErrors();
    $designRab->refresh();
    expect($designRab->status)->toBe(QuotationStatus::SentToClient)
        ->and($designRab->letter_number)->toMatch('#^\d+/OFF/Daiku/X/2026$#')
        ->and($designRab->valid_until->toDateString())->toBe('2026-11-02');
    $this->actingAs($this->marketing)->get(route('quotations.pdf', $designRab))->assertOk();

    // Langkah 10 · Klien (no login) opens the link and approves.
    auth()->logout();
    $token = $designRab->currentShareLink()->token;
    $this->get(route('public.quotation.show', $token))->assertOk();
    $this->post(route('public.quotation.approve', $token), ['agree' => true])->assertSessionHasNoErrors();

    $design = Design::where('lead_id', $lead->id)->sole();
    expect($designRab->fresh()->status)->toBe(QuotationStatus::ClientApproved)
        ->and($design->status)->toBe(DesignStatus::MenungguBayar);

    // A design is locked until paid.
    $this->actingAs($head)->post(route('design.assign', $design), [
        'pic_id' => $architect->id, 'start_date' => '2026-10-19', 'target_hari' => 14,
    ])->assertSessionHasErrors();

    // Langkah 11 · Terbitkan Invoice (jatuh tempo 3 hari) + Tandai Klien Sudah Bayar.
    $this->actingAs($this->marketing)->post(route('quotations.invoices.store', $designRab), ['due_date' => '2026-10-22'])
        ->assertSessionHasNoErrors();
    $designInvoice = $designRab->invoices()->sole();
    expect($designInvoice->number)->toMatch('#^\d+/INV/Daiku/X/2026$#');
    $this->actingAs($this->marketing)->get(route('finance.invoices.pdf', $designInvoice))->assertOk();

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $designInvoice), [
        'payment_proof_url' => 'https://drive.google.com/contoh-bukti-transfer-rina',
        'payment_photos' => [UploadedFile::fake()->image('bukti.jpg', 800, 600)],
    ])->assertSessionHasNoErrors();
    expect($designInvoice->fresh()->status->value)->toBe('MENUNGGU_VERIFIKASI')
        ->and(Notification::where('user_id', $this->finance->id)->where('type', 'invoice_awaiting_verification')->sole()->title)
        ->toBe('Menunggu Konfirmasi Pembayaran Jasa Desain · Rina Kusuma');

    // Langkah 12 · Finance: Verifikasi Pembayaran (BCA 5835, hari ini).
    $this->actingAs($this->finance)->get(route('finance.invoices.verification', ['verify' => $designInvoice->id]))->assertOk();
    $this->actingAs($this->finance)->post(route('finance.invoices.verify', $designInvoice), [
        'bank_account_id' => $this->bca->id,
        'paid_date' => '2026-10-19',
    ])->assertSessionHasNoErrors();

    expect($designInvoice->fresh()->status->value)->toBe('TERVERIFIKASI')
        ->and((float) FinanceTransaction::whereKey($designInvoice->fresh()->finance_transaction_id)->value('amount'))->toBe(4_500_000.0)
        ->and($design->fresh()->status)->toBe(DesignStatus::MenungguPenugasan)
        ->and(journeyNotified($head, 'design_ready_to_assign'))->toBe(1);

    // ── Tahap 4 — Desain ─────────────────────────────────────────────────

    // Langkah 13 · Kepala Desain: Tugaskan Desain (PIC, mulai hari ini, 14 hari).
    $this->actingAs($head)->post(route('design.assign', $design), [
        'pic_id' => $architect->id, 'start_date' => '2026-10-19', 'target_hari' => 14,
    ])->assertSessionHasNoErrors();
    expect($design->fresh()->status)->toBe(DesignStatus::Desain)
        ->and(journeyNotified($architect, 'design_assigned'))->toBe(1);

    // Langkah 14 · Arsitek saves the WA group + the design link.
    $this->actingAs($architect)->put(route('design.links.update', $design), [
        'whatsapp_group_url' => 'https://chat.whatsapp.com/ContohGrupRina',
        'design_urls' => ['https://drive.google.com/contoh-desain-kafe-rina-v1'],
    ])->assertSessionHasNoErrors();
    expect($design->fresh()->links_updated_by)->toBe($architect->id);

    // Langkah 15 · Desain Disetujui Klien → RAB Proyek asked from the Estimator.
    $this->actingAs($architect)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();
    $design->refresh();
    expect($design->status)->toBe(DesignStatus::AccDesain)
        ->and($design->client_acc_by)->toBe($architect->id);

    $rab = Quotation::where('lead_id', $lead->id)->where('type', 'PROYEK')->sole();
    expect($rab->status)->toBe(QuotationStatus::Diminta);

    // ── Tahap 5 — RAB Proyek ─────────────────────────────────────────────

    // Langkah 16 · Estimator: items per bagian (volume from P/T/L), diskon, pembulatan, skema 3 baris.
    $this->actingAs($estimator)->post(route('quotations.start', $rab))->assertSessionHasNoErrors();
    $this->actingAs($estimator)->put(route('quotations.items.update', $rab), [
        'sections' => [
            ['name' => 'Pekerjaan Persiapan', 'items' => [
                ['description' => 'Bongkar plafon & partisi lama', 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 3_500_000],
            ]],
            ['name' => 'Interior', 'items' => [
                ['description' => 'Meja bar HPL motif kayu', 'dim_length' => 4, 'dim_width_height' => 1.1, 'qty' => 1, 'unit_id' => unitId('unit'), 'unit_price' => 18_000_000],
                ['description' => 'Partisi rangka besi + kaca', 'dim_length' => 6, 'dim_width_height' => 2.4, 'qty' => 14.4, 'qty_source' => 'PxTL', 'unit_id' => unitId('m2'), 'unit_price' => 950_000],
                ['description' => 'Mural dinding', 'dim_length' => 5, 'dim_width_height' => 3, 'qty' => 15, 'qty_source' => 'PxTL', 'unit_id' => unitId('m2'), 'unit_price' => 350_000],
                ['description' => 'List gypsum keliling', 'dim_length' => 18, 'qty' => 18, 'qty_source' => 'P', 'unit_id' => unitId('m/lari'), 'unit_price' => 85_000],
            ]],
            ['name' => 'Listrik', 'items' => [
                ['description' => 'Titik lampu gantung', 'qty' => 12, 'unit_id' => unitId('titik'), 'unit_price' => 450_000],
                ['description' => 'Tambah daya & panel', 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 6_500_000],
            ]],
        ],
        'discount_amount' => 2_000_000,
        'rounded_total' => 51_860_000,
    ])->assertSessionHasNoErrors();

    $rab->refresh();
    expect((float) $rab->items_total)->toBe(53_860_000.0)
        ->and((float) $rab->total_amount)->toBe(51_860_000.0)
        ->and($rab->items()->where('description', 'Partisi rangka besi + kaca')->value('qty_source'))->toBe('PxTL')
        ->and($rab->items()->where('description', 'List gypsum keliling')->value('qty_source'))->toBe('P');

    $this->actingAs($estimator)->put(route('quotations.paymentTerms.update', $rab), [
        'terms' => [
            ['label' => 'DP', 'percentage' => 50, 'trigger' => 'DI_MUKA'],
            ['label' => 'Termin 2', 'percentage' => 30, 'trigger' => 'MILESTONE', 'milestone_name' => 'Pemasangan Interior'],
            ['label' => 'Pelunasan', 'percentage' => 20, 'trigger' => 'PROYEK_SELESAI'],
        ],
    ])->assertSessionHasNoErrors();
    $this->actingAs($estimator)->post(route('quotations.submit', $rab))->assertSessionHasNoErrors();

    // Langkah 17 · PM: Setujui RAB; the CEO is only told and may add a note.
    $this->actingAs($pm)->post(route('quotations.review', $rab), [
        'decision' => 'approve',
        'items' => $rab->items()->get()->map(fn ($item) => ['item_id' => $item->id, 'verdict' => 'OK'])->all(),
    ])->assertSessionHasNoErrors();
    expect($rab->fresh()->status)->toBe(QuotationStatus::ReadyToSend)
        ->and(journeyNotified($ceo, 'quotation_project_approved'))->toBe(1);

    $this->actingAs($ceo)->post(route('quotations.review', $rab), ['decision' => 'return', 'note' => 'x'])->assertForbidden();
    $this->actingAs($ceo)->post(route('quotations.notes.store', $rab), ['note' => 'Pastikan panel listrik pakai merk yang sama dengan gedung.'])
        ->assertSessionHasNoErrors();
    expect($rab->notes()->count())->toBe(1);

    // Langkah 18 · Marketing → Klien: Setujui Penawaran.
    $this->actingAs($this->marketing)->post(route('quotations.sendToClient', $rab))->assertSessionHasNoErrors();
    auth()->logout();
    $this->post(route('public.quotation.approve', $rab->fresh()->currentShareLink()->token), ['agree' => true])->assertSessionHasNoErrors();

    $opening = ProjectOpening::where('quotation_id', $rab->id)->sole();
    expect($rab->fresh()->status)->toBe(QuotationStatus::ClientApproved)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Closing)
        ->and($opening->opener_id)->toBe($pm->id)
        ->and(journeyNotified($pm, 'project_opening_pending'))->toBe(1);

    // ── Tahap 6 — Buka proyek & tagihan DP ───────────────────────────────

    // Langkah 19 · the PM who approved the RAB opens the project; the CEO can't.
    $openPayload = [
        'name' => 'Interior Kafe Rina Kusuma',
        'pm_id' => $pm->id,
        'assistant_pm_id' => $asistenPm->id,
        'team_lead_name' => 'Pak Darto',
        'supervisor_id' => $qa->id,
        'opened_on' => '2026-10-19',
        'start_date' => '2026-10-19',
    ];
    $this->actingAs($ceo)->post(route('projects.openings.open', $opening), $openPayload)->assertForbidden();
    $this->actingAs($pm)->post(route('projects.openings.open', $opening), $openPayload)->assertSessionHasNoErrors();

    $project = Project::where('lead_id', $lead->id)->sole();
    $termins = Termin::where('project_id', $project->id)->orderBy('termin_number')->get();
    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->phase)->toBe(ProjectPhase::GambarKerja)
        ->and($project->supervisor_id)->toBe($qa->id)
        ->and((float) $project->contract_value)->toBe(51_860_000.0)
        ->and($termins->map(fn (Termin $t) => (float) $t->amount)->all())->toBe([25_930_000.0, 15_558_000.0, 10_372_000.0]);
    foreach ([$ceo, $this->finance, $logistics, $this->marketing, $qa] as $user) {
        expect(journeyNotified($user, 'project_opened'))->toBe(1);
    }

    // Langkah 20 · Marketing bills the DP from the project's termin; Finance verifies.
    $this->actingAs($this->marketing)->post(route('finance.termins.invoices.store', $termins[0]), ['due_date' => '2026-10-22'])
        ->assertSessionHasNoErrors();
    journeyPay($this, $termins[0]->fresh()->invoice);
    expect($termins[0]->fresh()->status)->toBe(TerminStatus::Paid);

    // ── Tahap 7 — Pelaksanaan proyek ─────────────────────────────────────

    // Langkah 21 · PM: Alokasi Dana — 3 pos, every RAB item in its pos.
    $items = $rab->items()->get()->keyBy('description');
    $postItems = [
        'Persiapan' => ['Bongkar plafon & partisi lama'],
        'Interior' => ['Meja bar HPL motif kayu', 'Partisi rangka besi + kaca', 'Mural dinding', 'List gypsum keliling'],
        'Listrik' => ['Titik lampu gantung', 'Tambah daya & panel'],
    ];
    foreach ($postItems as $name => $descriptions) {
        $this->actingAs($pm)->post(route('projects.budget.posts.store', $project), ['name' => $name])->assertSessionHasNoErrors();
        $this->actingAs($pm)->post(route('projects.budget.allocate', $project), [
            'budget_post_id' => $project->budgetPosts()->where('name', $name)->value('id'),
            'item_ids' => collect($descriptions)->map(fn ($d) => $items[$d]->id)->all(),
        ])->assertSessionHasNoErrors();
    }
    $this->actingAs($pm)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
        ->has('budget.posts', 3)
        ->where('budget.posts.2.total', 11_900_000));
    // Marketing and the Asisten PM never get the figures.
    foreach ([$this->marketing, $asistenPm] as $viewer) {
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page->where('budget', null));
    }

    // Langkah 22a · Gambar Kerja: Kepala Desain assigns, the architect finishes → TM.
    $drawing = $project->workingDrawing;
    $this->actingAs($head)->post(route('design.working-drawings.assign', $drawing), [
        'pic_id' => $architect->id, 'start_date' => '2026-10-19', 'target_hari' => 3,
    ])->assertSessionHasNoErrors();
    $this->actingAs($architect)->put(route('design.working-drawings.links.update', $drawing), [
        'drawing_urls' => ['https://drive.google.com/gambar-kerja-kafe-rina'],
    ])->assertSessionHasNoErrors();
    $this->actingAs($architect)->post(route('design.working-drawings.complete', $drawing))->assertSessionHasNoErrors();
    expect($drawing->fresh()->status)->toBe(WorkingDrawingStatus::Selesai)
        ->and($project->fresh()->phase)->toBe(ProjectPhase::Tm)
        ->and(journeyNotified($pm, 'working_drawing_completed'))->toBe(1)
        ->and(journeyNotified($qa, 'working_drawing_completed'))->toBe(1);

    // Langkah 22 · PM: 3 timelines (the 2nd named like the Termin 2 trigger).
    foreach ([
        ['Persiapan & Bongkar', '2026-10-19', '2026-10-26'],
        ['Pemasangan Interior', '2026-10-27', '2026-11-18'],
        ['Finishing & Serah Terima', '2026-11-19', '2026-11-28'],
    ] as [$name, $start, $end]) {
        $this->actingAs($pm)->post(route('milestones.store', $project), ['name' => $name, 'start_date' => $start, 'target_date' => $end])
            ->assertSessionHasNoErrors();
    }
    [$persiapan, $pemasangan, $finishing] = Milestone::where('project_id', $project->id)->orderBy('start_date')->get()->all();

    // The Pengawas plans the materials beside the RAB items.
    $material = Material::factory()->create(['name' => 'Panel HPL motif kayu']);
    $this->actingAs($qa)->post(route('projects.materials.store', $project), ['material_id' => $material->id, 'source' => 'GUDANG', 'qty_planned' => 6])
        ->assertSessionHasNoErrors();

    // The PM's TM: date, minutes, attendees, Jasa Pengawas 5.000.000 / DP 10 %, Tanggal Selesai → TM Selesai.
    $this->actingAs($pm)->put(route('projects.technical-meeting.save', $project), [
        'meeting_date' => '2026-10-19',
        'notes' => 'Urutan kerja: bongkar → partisi & bar → mural → listrik.',
        'attendees' => [['user_id' => $qa->id], ['name' => 'Pak Darto (Kepala Tim)']],
        'fee_total' => 5_000_000,
        'dp_percent' => 10,
        'end_date' => '2026-11-28',
    ])->assertSessionHasNoErrors();
    $this->actingAs($pm)->post(route('projects.technical-meeting.complete', $project))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Persiapan);

    // The Pengawas plans the daily targets (each with a wage, each timeline at least one).
    $targets = [];
    foreach ([
        ['Bongkar plafon lama lantai 1', $persiapan, '2026-10-22', 250_000],
        ['Pasang rangka partisi', $pemasangan, '2026-10-28', 300_000],
        ['Pasang meja bar', $pemasangan, '2026-10-29', 300_000],
        ['Mural & list gypsum', $finishing, '2026-11-20', 350_000],
    ] as [$title, $milestone, $date, $wage]) {
        $this->actingAs($qa)->post(route('tasks.store', $project), [
            'milestone_id' => $milestone->id, 'title' => $title, 'assignee_id' => $tukang->id, 'due_date' => $date, 'rate_per_task' => $wage,
        ])->assertSessionHasNoErrors();
        $targets[$title] = Task::where('project_id', $project->id)->where('title', $title)->sole();
    }

    // Ajukan DP Pengawas → PM Setujui = Start Produksi → Finance Bayar.
    $this->actingAs($qa)->post(route('projects.supervisor-payments.dp', $project))->assertSessionHasNoErrors();
    $dpPayment = SupervisorPayment::where('project_id', $project->id)->sole();
    $this->actingAs($pm)->post(route('supervisor-payments.decide', $dpPayment), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Produksi)
        ->and(journeyNotified($logistics, 'production_started'))->toBe(1)
        // Sprint 28 K9 — Start Produksi starts the first timeline; the others wait their turn.
        ->and($persiapan->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and($pemasangan->fresh()->status)->toBe(MilestoneStatus::Pending)
        ->and($finishing->fresh()->status)->toBe(MilestoneStatus::Pending);
    $this->actingAs($this->finance)->post(route('finance.supervisor-payments.pay', $dpPayment), ['bank_account_id' => $this->bca->id])
        ->assertSessionHasNoErrors();
    expect($dpPayment->fresh()->status)->toBe(SupervisorPaymentStatus::Dibayar)
        ->and((float) $dpPayment->fresh()->amount)->toBe(500_000.0);

    // Langkah 23 · Tukang (phone) on the target's day: status + kendala = the day's report.
    journeyAt('2026-10-22', '16:00:00');
    $bongkar = $targets['Bongkar plafon lama lantai 1'];
    $this->actingAs($tukang)->get(route('today.index'))->assertOk();
    $this->actingAs($tukang)->patch(route('tasks.updateStatus', $bongkar), [
        'status' => 'ONPROGRESS', 'kendala' => 'Bongkar plafon 60% selesai, besok lanjut sisi bar.',
    ])->assertSessionHasNoErrors();
    expect(DailyTaskForm::where('task_id', $bongkar->id)->forDate('2026-10-22')->exists())->toBeTrue();

    // The Tukang can never change the title / date of a task.
    $this->actingAs($tukang)->put(route('tasks.update', $bongkar), [
        'milestone_id' => $persiapan->id, 'title' => 'Diubah tukang', 'assignee_id' => $tukang->id, 'due_date' => '2026-10-23',
    ])->assertForbidden();

    // Done → "Minta dicek" → the PM sets it Selesai.
    $this->actingAs($tukang)->patch(route('tasks.updateStatus', $bongkar), ['status' => 'PENGECEKAN'])->assertSessionHasNoErrors();
    $this->actingAs($pm)->patch(route('tasks.updateStatus', $bongkar), ['status' => 'DONE'])->assertSessionHasNoErrors();
    expect($bongkar->fresh()->status)->toBe(TaskStatus::Done);

    // Friday: the Pengawas's weekly report, the PM approves with this week's fee.
    journeyAt('2026-10-23', '15:00:00');
    $this->actingAs($qa)->post(route('projects.weekly-reports.store', $project), ['notes' => 'Bongkar lantai 1 selesai, material partisi sudah dipesan.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($pm)->post(route('weekly-reports.decide', WeeklyReport::where('project_id', $project->id)->sole()), ['decision' => 'approve', 'amount' => 400_000])
        ->assertSessionHasNoErrors();

    // Langkah 24 · PM: realisasi biaya; an overrun goes to the CEO.
    $lampu = BudgetLine::where('quotation_item_id', $items['Titik lampu gantung']->id)->sole();
    $panel = BudgetLine::where('quotation_item_id', $items['Tambah daya & panel']->id)->sole();
    $this->actingAs($pm)->post(route('projects.budget.realizations.store', [$project, $lampu]), ['qty_actual' => 12, 'unit_cost' => 300_000])
        ->assertSessionHasNoErrors();
    $this->actingAs($pm)->post(route('projects.budget.realizations.store', [$project, $panel]), ['qty_actual' => 1, 'unit_cost' => 9_000_000])
        ->assertSessionHasErrors('overrun');
    $this->actingAs($pm)->post(route('projects.budget.overruns.store', [$project, $panel]), [
        'qty_actual' => 1, 'unit_cost' => 9_000_000, 'reason' => 'Harga panel naik dari supplier, perlu MCB tambahan.',
    ])->assertSessionHasNoErrors();
    $overrun = BudgetOverrunRequest::sole();
    expect(journeyNotified($ceo, 'budget_overrun_requested'))->toBe(1);
    $this->actingAs($ceo)->post(route('projects.budget.overruns.decide', $overrun), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect(journeyNotified($pm, 'budget_overrun_approved'))->toBe(1);

    // ── Tahap 8 — QA & termin berikutnya ─────────────────────────────────

    // Langkah 25 · PM: Tandai Selesai → the Pengawas's QA form.
    $this->actingAs($pm)->post(route('milestones.markDone', $persiapan))->assertSessionHasNoErrors();
    $qaForm = QaForm::where('milestone_id', $persiapan->id)->sole();
    expect($persiapan->fresh()->status)->toBe(MilestoneStatus::QaWaiting)
        ->and(journeyNotified($qa, 'qa_form_created'))->toBe(1);

    // Langkah 26 · QA: checklist → Setujui QA → the next timeline runs.
    $checklist = ['decision' => 'approve', 'checklist_data' => [['label' => 'Sesuai gambar kerja', 'passed' => true, 'note' => 'Rapi']], 'notes' => null];
    $this->actingAs($qa)->get(route('qa-forms.show', $qaForm))->assertInertia(fn (Assert $page) => $page->where('canReview', true));
    $this->actingAs($qa)->put(route('qa-forms.update', $qaForm), $checklist)->assertSessionHasNoErrors();
    expect($persiapan->fresh()->status)->toBe(MilestoneStatus::Completed)
        ->and($pemasangan->fresh()->status)->toBe(MilestoneStatus::InProgress);

    // Langkah 27 · Pemasangan Interior: the targets → ≥ 70 % → Berangkat ke Lokasi.
    foreach (['Pasang rangka partisi' => '2026-10-28', 'Pasang meja bar' => '2026-10-29'] as $title => $day) {
        journeyAt($day, '16:00:00');
        $this->actingAs($tukang)->patch(route('tasks.updateStatus', $targets[$title]), ['status' => 'DONE'])->assertSessionHasNoErrors();
        // Sprint 29 Sub 04 — "Selesai" waits for the Pengawas's approval.
        expect($targets[$title]->fresh()->status)->toBe(TaskStatus::Pengecekan);
        $this->actingAs($qa)->post(route('tasks.approve', $targets[$title]))->assertSessionHasNoErrors();
    }
    expect($project->fresh()->actualProgress())->toBe(75)
        ->and(journeyNotified($pm, 'project_progress_reached'))->toBe(1);
    $this->actingAs($pm)->post(route('projects.departure.approve', $project))->assertSessionHasNoErrors();
    expect($project->fresh()->phase)->toBe(ProjectPhase::Pemasangan);

    // A Temuan Finishing holds the timeline's QA until its repair task is done.
    $this->actingAs($qa)->post(route('projects.site-findings.store', $project), [
        'description' => 'Sambungan kaca partisi kurang rapat', 'milestone_id' => $pemasangan->id,
    ])->assertSessionHasNoErrors();
    $finding = SiteFinding::sole();
    $this->actingAs($qa)->post(route('site-findings.repair-task', $finding), ['assignee_id' => $tukang->id, 'due_date' => '2026-10-30'])
        ->assertSessionHasNoErrors();
    journeyAt('2026-10-30', '15:00:00');
    $this->actingAs($tukang)->patch(route('tasks.updateStatus', $finding->fresh()->task), ['status' => 'DONE'])->assertSessionHasNoErrors();
    $this->actingAs($qa)->post(route('tasks.approve', $finding->fresh()->task))->assertSessionHasNoErrors();
    expect($finding->fresh()->status)->toBe(SiteFindingStatus::Selesai);

    $this->actingAs($pm)->post(route('milestones.markDone', $pemasangan))->assertSessionHasNoErrors();
    $this->actingAs($qa)->put(route('qa-forms.update', QaForm::where('milestone_id', $pemasangan->id)->sole()), $checklist)->assertSessionHasNoErrors();
    expect($pemasangan->fresh()->status)->toBe(MilestoneStatus::Completed);

    // Termin 2 is due (the daily reminder job) → Marketing bills it → Finance verifies.
    journeyAt('2026-10-31', '07:00:00');
    app(TerminService::class)->remindInvoices();
    expect(journeyNotified($this->marketing, 'termin_invoice_due'))->toBeGreaterThanOrEqual(1);
    $this->actingAs($this->marketing)->post(route('finance.termins.invoices.store', $termins[1]), ['due_date' => '2026-11-03'])
        ->assertSessionHasNoErrors();
    $terminInvoice = $termins[1]->fresh()->invoice;
    expect($terminInvoice->fresh()->paymentTypeLabel())->toBe('Termin 2 dari 3 · 30%');
    journeyPay($this, $terminInvoice);

    // Finishing & Serah Terima → QA → the project completes on its own.
    journeyAt('2026-11-20', '16:00:00');
    $this->actingAs($tukang)->patch(route('tasks.updateStatus', $targets['Mural & list gypsum']), ['status' => 'DONE'])->assertSessionHasNoErrors();
    $this->actingAs($pm)->post(route('milestones.markDone', $finishing))->assertSessionHasNoErrors();
    $this->actingAs($qa)->put(route('qa-forms.update', QaForm::where('milestone_id', $finishing->id)->sole()), $checklist)->assertSessionHasNoErrors();

    expect($project->fresh()->status)->toBe(ProjectStatus::Completed);

    // Pelunasan is due → invoice → verified.
    app(TerminService::class)->remindInvoices();
    $this->actingAs($this->marketing)->post(route('finance.termins.invoices.store', $termins[2]), ['due_date' => '2026-11-23'])
        ->assertSessionHasNoErrors();
    journeyPay($this, $termins[2]->fresh()->invoice);

    // ── Tahap 9 — Pengecekan akhir ───────────────────────────────────────

    // Riwayat RAB + Informasi Pembayaran on the lead: everything Lunas.
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $lead))->assertInertia(fn (Assert $page) => $page
        ->has('payments.items', 4)
        ->where('payments.items', fn ($rows) => collect($rows)->every(fn ($row) => $row['state'] === 'LUNAS'))
        ->where('payments.summary.unpaid', 0));
    // The architect and the Estimator don't see the card.
    foreach ([$architect, $estimator] as $viewer) {
        $response = $this->actingAs($viewer)->get(route('crm.leads.show', $lead));
        if ($response->status() === 200) {
            $response->assertInertia(fn (Assert $page) => $page->where('payments', null));
        } else {
            $response->assertForbidden();
        }
    }

    // Pemasukan: jasa desain, DP, termin 2, pelunasan.
    $income = FinanceTransaction::where('type', 'PEMASUKAN')->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all();
    expect($income)->toBe([4_500_000.0, 25_930_000.0, 15_558_000.0, 10_372_000.0]);
    $this->actingAs($this->finance)->get(route('finance.transactions.index'))->assertOk();

    // Termin: all Paid.
    expect(Termin::where('project_id', $project->id)->pluck('status')->map->value->unique()->all())->toBe(['PAID']);
    $this->actingAs($this->finance)->get(route('finance.termins.index'))->assertOk();

    // Anggaran vs realisasi, overrun included (PM / CEO / Finance).
    foreach ([$pm, $ceo, $this->finance] as $viewer) {
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertInertia(fn (Assert $page) => $page
            ->where('budget.summary.realizedTotal', 12_600_000));
    }

    // Upah tukang, Penalti, Audit Trail, Analytics.
    $this->actingAs($this->finance)->get(route('finance.staffPayments.index'))->assertOk();
    $this->actingAs($this->finance)->get(route('penalties.index'))->assertOk();
    $this->actingAs($ceo)->get(route('audit-logs.index'))->assertOk();
    $this->actingAs($ceo)->get(route('analytics.index'))->assertOk();
    expect(AuditLog::where('action', 'finance.invoice_issued')->count())->toBe(4);
});
