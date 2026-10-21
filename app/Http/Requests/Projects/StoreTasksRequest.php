<?php

namespace App\Http\Requests\Projects;

/**
 * Sprint 29 Sub 04 (K5) — one daily target for several tukang × the working
 * days of a range, in one request. Everything but the tukang and the date is
 * StoreTaskRequest's; each row is still checked against its timeline
 * (TaskService::createMany()).
 */
class StoreTasksRequest extends StoreTaskRequest
{
    public const MAX_ASSIGNEES = 10;

    public const MAX_DAYS = 31;

    public function rules(): array
    {
        $rules = parent::rules();
        $assignee = $rules['assignee_id'];
        unset($rules['assignee_id'], $rules['due_date']);

        return [
            ...$rules,
            'assignee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSIGNEES],
            'assignee_ids.*' => array_values(array_filter($assignee, fn ($rule) => $rule !== 'required')),
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', 'before_or_equal:'.$this->lastAllowedDay()],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'assignee_ids.required' => 'Pilih minimal satu tukang.',
            'assignee_ids.min' => 'Pilih minimal satu tukang.',
            'assignee_ids.max' => 'Maksimal '.self::MAX_ASSIGNEES.' tukang sekaligus.',
            'date_from.required' => 'Tanggal mulai wajib diisi.',
            'date_to.required' => 'Tanggal akhir wajib diisi.',
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
            'date_to.before_or_equal' => 'Rentang maksimal '.self::MAX_DAYS.' hari.',
        ];
    }

    private function lastAllowedDay(): string
    {
        $from = strtotime((string) $this->input('date_from')) ?: time();

        return date('Y-m-d', $from + (self::MAX_DAYS - 1) * 86_400);
    }
}
