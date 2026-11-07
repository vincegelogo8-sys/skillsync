<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    protected $fillable = [
        'student_number',
        'course',
        'year_level',
        'section',
    ];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function researchProposals(): HasMany
    {
        return $this->hasMany(ResearchProposal::class);
    }

    /**
     * True when any of this student's proposals has an active adviser assignment.
     * Completed or cancelled assignments do not count.
     */
    public function hasActiveAdviser(): bool
    {
        return AdviserAssignment::where('status', AdviserAssignment::STATUS_ACTIVE)
            ->whereHas(
                'researchProposal',
                fn ($query) => $query->where('student_profile_id', $this->id)
            )
            ->exists();
    }
}