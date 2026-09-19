<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FacultyProfile extends Model
{
    // Ownership and the Admin-managed limit are not faculty-editable fields.
    protected $fillable = [
        'department',
    ];

    protected $attributes = [
        'advisory_limit' => 5,
    ];

    protected function casts(): array
    {
        return [
            'advisory_limit' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expertise(): HasMany
    {
        return $this->hasMany(FacultyExpertise::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(FacultyPreference::class);
    }

    public function competency(): HasOne
    {
        return $this->hasOne(FacultyCompetency::class);
    }

    public function assessmentAttempts(): HasMany
    {
        return $this->hasMany(SkillsAssessmentAttempt::class);
    }

    public function latestCompletedAssessment(): HasOne
    {
        return $this->hasOne(SkillsAssessmentAttempt::class)->ofMany(
            ['completed_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->whereNotNull('completed_at'),
        );
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AdviserAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->where('status', AdviserAssignment::STATUS_ACTIVE);
    }
}
