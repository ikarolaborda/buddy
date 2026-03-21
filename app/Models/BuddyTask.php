<?php

namespace App\Models;

use App\Enums\ProblemType;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class BuddyTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'source_agent',
        'repo',
        'branch',
        'task_summary',
        'problem_type',
        'constraints',
        'evidence',
        'requested_outcome',
        'status',
        'attempt_count',
    ];

    protected function casts(): array
    {
        return [
            'problem_type' => ProblemType::class,
            'status' => TaskStatus::class,
            'constraints' => 'array',
            'evidence' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            $task->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BuddyRun::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(BuddyArtifact::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BuddyQuestion::class);
    }

    public function memoryReferences(): HasMany
    {
        return $this->hasMany(BuddyMemoryReference::class);
    }

    public function decisionLogs(): HasMany
    {
        return $this->hasMany(BuddyDecisionLog::class);
    }

    public function recommendations(): HasManyThrough
    {
        return $this->hasManyThrough(BuddyRecommendation::class, BuddyRun::class);
    }

    public function latestRecommendation(): ?BuddyRecommendation
    {
        return $this->recommendations()->latest()->first();
    }

    public function latestRun(): ?BuddyRun
    {
        return $this->runs()->latest()->first();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
