<?php

namespace App\Http\Requests\Buddy;

use App\Enums\ArtifactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachArtifactRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::enum(ArtifactType::class)],
            'content' => ['required', 'string', 'max:100000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
