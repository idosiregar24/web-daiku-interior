<?php

namespace App\Console\Commands;

use App\Enums\LeadSurveyStatus;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Services\LeadService;
use App\Services\QuotationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 17 Sub 02 (K4) — one-off repair for surveys scheduled *after* their
 * RAB Jasa Survey was asked for (before the fix they never got linked, so
 * verifying the invoice left them MENUNGGU_BAYAR — e.g. lead #37). Links
 * each waiting survey to its RAB and marks it SIAP when the invoice is
 * already verified. Idempotent; `--dry-run` rolls every change back.
 */
class RelinkSurveys extends Command
{
    protected $signature = 'daiku:relink-surveys {--dry-run : Tampilkan saja, jangan simpan}';

    protected $description = 'Tautkan survey luar Pekanbaru yang menunggu bayar ke RAB Jasa Survey-nya, lalu tandai SIAP bila sudah lunas';

    public function handle(QuotationService $quotations, LeadService $leads): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        $waiting = Lead::query()
            ->whereHas('surveys', fn ($query) => $query->where('status', LeadSurveyStatus::MenungguBayar->value))
            ->orderBy('id')
            ->get();

        foreach ($waiting as $lead) {
            DB::beginTransaction();

            $linked = $quotations->linkSurveyToRab($lead);

            $ready = $lead->surveys()
                ->where('status', LeadSurveyStatus::MenungguBayar->value)
                ->whereNotNull('quotation_id')
                ->get()
                ->filter(fn (LeadSurvey $survey) => $leads->isSurveyPaid($survey));

            // markSurveyReady() notifies Marketing — never on a dry run.
            if (! $dryRun) {
                $ready->each(fn (LeadSurvey $survey) => $leads->markSurveyReady($survey));
            }

            if ($linked || $ready->isNotEmpty()) {
                $changed++;
                $this->line(sprintf(
                    'Lead #%d %s: %s%s',
                    $lead->id,
                    $lead->client_name,
                    $linked ? "survey #{$linked->sequence} ditautkan ke RAB #{$linked->quotation_id}" : 'tautan sudah ada',
                    $ready->isNotEmpty() ? ' → SIAP (invoice sudah diverifikasi)' : ' (belum lunas)',
                ));
            }

            $dryRun ? DB::rollBack() : DB::commit();
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."{$changed} lead diperbaiki.");

        return self::SUCCESS;
    }
}
