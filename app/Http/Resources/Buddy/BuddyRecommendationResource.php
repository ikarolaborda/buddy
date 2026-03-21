<?php

namespace App\Http\Resources\Buddy;

use App\Models\BuddyRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BuddyRecommendation */
class BuddyRecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'accepted' => $this->accepted,
            'confidence' => $this->confidence->value,
            'summary' => $this->summary,
            'recommended_plan' => $this->recommended_plan ?? [],
            'rejected_reasons' => $this->rejected_reasons ?? [],
            'required_followups' => $this->required_followups ?? [],
            'risks' => $this->risks ?? [],
            'next_actions' => $this->next_actions ?? [],
            'memory_hits' => $this->memory_hits ?? [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
