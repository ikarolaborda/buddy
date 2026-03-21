<?php

namespace App\Http\Resources\Buddy;

use App\Models\BuddyTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BuddyTask */
class BuddyTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->ulid,
            'source_agent' => $this->source_agent,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'task_summary' => $this->task_summary,
            'problem_type' => $this->problem_type->value,
            'constraints' => $this->constraints,
            'requested_outcome' => $this->requested_outcome,
            'status' => $this->status->value,
            'attempt_count' => $this->attempt_count,
            'runs_count' => $this->whenCounted('runs'),
            'artifacts_count' => $this->whenCounted('artifacts'),
            'recommendation' => new BuddyRecommendationResource(
                $this->whenLoaded('recommendations', fn () => $this->latestRecommendation()),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
