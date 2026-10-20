<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/** Lịch sử ứng viên: created | stage_change | status_change | note. */
class CandidateEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['candidate_id', 'type', 'body', 'from_stage', 'to_stage', 'created_by'];

    protected static function booted(): void
    {
        static::creating(function (CandidateEvent $e) {
            $e->created_by ??= Auth::id();
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
