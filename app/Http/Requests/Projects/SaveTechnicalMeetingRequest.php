<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Models\TechnicalMeeting;
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
            // Sprint 34 — JADWAL (ahead: time + the people invited) or LANGSUNG (held now: the form only).
            'mode' => ['nullable', Rule::in([TechnicalMeeting::MODE_SCHEDULED, TechnicalMeeting::MODE_NOW])],
            'meeting_time' => ['nullable', 'date_format:H:i', 'required_if:mode,'.TechnicalMeeting::MODE_SCHEDULED],
            'notes' => ['nullable', 'string', 'max:10000'],
            'attendees' => ['nullable', 'array', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                // A schedule is an invitation — at least one person with an account gets it.
                if ($this->input('mode') === TechnicalMeeting::MODE_SCHEDULED && ! collect($value ?? [])->contains(fn ($row) => filled($row['user_id'] ?? null))) {
                    $fail('Pilih minimal satu orang yang punya akun — merekalah yang menerima undangan & pengingat TM.');
                }
            }],
            // Sprint 33 — nobody twice (the picker never offers them again either).
            'attendees.*.user_id' => ['nullable', 'integer', 'distinct', Rule::exists('users', 'id')],
            'attendees.*.name' => ['nullable', 'string', 'max:120', 'distinct:ignore_case', 'required_without:attendees.*.user_id'],
            // Sprint 29 Sub 08 — with work items the total is Σ upah per item, not typed here.
            'fee_total' => [Rule::requiredIf(fn () => $project->supervisorFee()->exists() && ! $project->workItems()->exists()), 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            // Sprint 33 — with work items the DP is 10 % of Σ upah per item, the PM adjusts the amount when approving it.
            'dp_percent' => [Rule::requiredIf(fn () => ! $project->workItems()->exists()), 'nullable', 'numeric', 'min:0', 'max:100'],
            'end_date' => ['nullable', 'date', ...($project->start_date ? ['after_or_equal:'.$project->start_date->toDateString()] : [])],
        ];
    }

    public function messages(): array
    {
        return [
            'meeting_date.required' => 'Tanggal TM wajib diisi.',
            'meeting_time.required_if' => 'Jam TM wajib diisi untuk menjadwalkan TM.',
            'meeting_time.date_format' => 'Jam TM harus berformat JJ:MM.',
            'notes.max' => 'Notulen terlalu panjang.',
            'attendees.max' => 'Maksimal 50 peserta.',
            'attendees.*.name.required_without' => 'Isi nama peserta atau pilih dari daftar.',
            'attendees.*.user_id.distinct' => 'Peserta yang sama tidak boleh ditambahkan dua kali.',
            'attendees.*.name.distinct' => 'Nama peserta yang sama tidak boleh ditambahkan dua kali.',
            'fee_total.required' => 'Nilai upah tukang wajib diisi.',
            'fee_total.numeric' => 'Nilai upah tukang harus berupa angka.',
            'dp_percent.required' => 'DP % wajib diisi.',
            'dp_percent.max' => 'DP maksimal 100%.',
            'end_date.after_or_equal' => 'Tanggal Selesai proyek tidak boleh sebelum Tanggal Mulai.',
        ];
    }
}
