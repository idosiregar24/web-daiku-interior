<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 24 D3/D4/E1/E2 — the TM form: date, minutes, attendees (a user or
 * a typed name), Jasa Pengawas total + DP %, the project's Tanggal Selesai.
 * The fee may stay empty while none is set yet — the PM can save the TM's
 * date (its schedule) first; "TM Selesai" still needs it.
 * Route `role:PM|ASISTEN_PM` + ProjectPolicy::runMeeting(); the phase rule
 * is TechnicalMeetingService::save()'s.
 */
class SaveTechnicalMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('editMeeting', $this->route('project'));
    }

    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'meeting_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'attendees' => ['nullable', 'array', 'max:50'],
            'attendees.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'attendees.*.name' => ['nullable', 'string', 'max:120', 'required_without:attendees.*.user_id'],
            // Sprint 29 Sub 08 — with work items the total is Σ upah per item, not typed here.
            'fee_total' => [Rule::requiredIf(fn () => $project->supervisorFee()->exists() && ! $project->workItems()->exists()), 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'dp_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'end_date' => ['nullable', 'date', ...($project->start_date ? ['after_or_equal:'.$project->start_date->toDateString()] : [])],
        ];
    }

    public function messages(): array
    {
        return [
            'meeting_date.required' => 'Tanggal TM wajib diisi.',
            'notes.max' => 'Notulen terlalu panjang.',
            'attendees.max' => 'Maksimal 50 peserta.',
            'attendees.*.name.required_without' => 'Isi nama peserta atau pilih dari daftar.',
            'fee_total.required' => 'Nilai upah tukang wajib diisi.',
            'fee_total.numeric' => 'Nilai upah tukang harus berupa angka.',
            'dp_percent.required' => 'DP % wajib diisi.',
            'dp_percent.max' => 'DP maksimal 100%.',
            'end_date.after_or_equal' => 'Tanggal Selesai proyek tidak boleh sebelum Tanggal Mulai.',
        ];
    }
}
