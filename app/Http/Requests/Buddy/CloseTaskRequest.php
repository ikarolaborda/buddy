<?php

namespace App\Http\Requests\Buddy;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
