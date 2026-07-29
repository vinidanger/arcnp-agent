<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AgentJob extends Model
{
    protected $fillable = [
        'uuid',
        'correlation_id',
        'action',
        'payload',
        'status',
        'result',
        'error',
        'attempts',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $job) {
            $job->uuid ??= (string) Str::uuid();
        });
    }
}
