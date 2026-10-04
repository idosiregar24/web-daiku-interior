<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 12 decision #2 — follow-ups FU-1, FU-2, … on the lead detail
 * timeline. Marketing + CEO (route middleware); rules in LeadService.
 */
class LeadFollowUpController extends Controller
{
    public function store(Request $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $data = $request->validate(
            ['scheduled_date' => ['required', 'date']],
            ['scheduled_date.required' => 'Tanggal follow-up wajib diisi.'],
        );

        $followUp = $service->addFollowUp($lead, $data, $request->user());

        return back()->with('success', "FU-{$followUp->sequence} dijadwalkan.");
    }

    public function complete(Request $request, LeadFollowUp $followUp, LeadService $service): RedirectResponse
    {
        $data = $request->validate(
            ['result_note' => ['required', 'string', 'max:2000']],
            ['result_note.required' => 'Catatan hasil follow-up wajib diisi.'],
        );

        $service->completeFollowUp($followUp, $data);

        return back()->with('success', "FU-{$followUp->sequence} ditandai selesai.");
    }
}
