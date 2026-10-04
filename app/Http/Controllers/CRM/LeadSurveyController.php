<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\LeadSurveyRequest;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 12 decision #3 — site surveys on the lead detail timeline.
 * Marketing + CEO (route middleware); rules in LeadService. There is no
 * route to mark an outside-Pekanbaru survey ready: that only happens when
 * Finance verifies its payment (LeadService::markSurveyReady(), Sub 6).
 */
class LeadSurveyController extends Controller
{
    public function store(LeadSurveyRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $survey = $service->scheduleSurvey($lead, $request->validated(), $request->user());

        return back()->with('success', "Survey #{$survey->sequence} dijadwalkan.");
    }

    public function update(LeadSurveyRequest $request, LeadSurvey $survey, LeadService $service): RedirectResponse
    {
        $service->updateSurvey($survey, $request->validated());

        return back()->with('success', 'Jadwal survey diperbarui.');
    }

    public function complete(Request $request, LeadSurvey $survey, LeadService $service): RedirectResponse
    {
        $data = $request->validate(
            ['result_note' => ['required', 'string', 'max:2000']],
            ['result_note.required' => 'Catatan hasil survey wajib diisi.'],
        );

        $service->completeSurvey($survey, $data);

        return back()->with('success', "Survey #{$survey->sequence} selesai.");
    }

    public function cancel(Request $request, LeadSurvey $survey, LeadService $service): RedirectResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Alasan pembatalan survey wajib diisi.'],
        );

        $service->cancelSurvey($survey, $data['reason'], $request->user());

        return back()->with('success', "Survey #{$survey->sequence} dibatalkan.");
    }
}
