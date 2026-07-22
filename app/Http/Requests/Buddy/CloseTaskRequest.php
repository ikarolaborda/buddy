<?php

namespace App\Http\Requests\Buddy;

use App\Enums\TaskOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'learnings_summary' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', Rule::enum(TaskOutcome::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
