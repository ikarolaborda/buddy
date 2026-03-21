<?php

namespace App\Http\Requests\Buddy;

use App\Enums\ProblemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTaskRequest extends FormRequest
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
            'source_agent' => ['required', 'string', 'max:255'],
            'repo' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'task_summary' => ['required', 'string', 'max:10000'],
            'problem_type' => ['required', 'string', Rule::enum(ProblemType::class)],
            'constraints' => ['nullable', 'array'],
            'constraints.*' => ['string'],
            'attempts' => ['nullable', 'array'],
            'evidence' => ['nullable', 'array'],
            'artifacts' => ['nullable', 'array'],
            'artifacts.*.type' => ['required_with:artifacts', 'string'],
            'artifacts.*.content' => ['required_with:artifacts', 'string'],
            'artifacts.*.metadata' => ['nullable', 'array'],
            'requested_outcome' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
