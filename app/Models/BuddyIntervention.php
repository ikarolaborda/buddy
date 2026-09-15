<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class BuddyIntervention extends Model
{
    use HasUlids;

    protected $fillable = ['buddy_task_id', 'api_client_id', 'request_id', 'request_hash', 'action', 'status', 'context', 'result'];

    protected function casts(): array
    {
        return ['context' => 'array', 'result' => 'array'];
    }

    public function response(): array
    {
        return [
            'intervention_id' => $this->id,
            'action' => $this->action,
            'status' => $this->status,
            'context' => $this->context,
            'result' => $this->result,
        ];
    }
}
