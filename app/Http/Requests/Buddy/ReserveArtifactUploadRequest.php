<?php

namespace App\Http\Requests\Buddy;

use App\Enums\ArtifactType;
use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReserveArtifactUploadRequest extends FormRequest
{
    use AuthorizesTaskAccess;

    /*
     * Authorization runs before validation, so a disabled flag or a foreign
     * task answers 404 before the payload shape can leak a 422.
     */
    public function authorize(): bool
    {
        abort_unless(config('buddy.edge.artifacts'), 404);

        $this->authorizeTaskAccess($this, $this->route('task'));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                Rule::enum(ArtifactType::class),
            ],
            'size_bytes' => [
                'required',
                'integer',
                'min:1',
            ],
            'media_type' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
