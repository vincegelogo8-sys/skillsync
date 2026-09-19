<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillsAssessmentAttempt extends Model
{
    protected function casts(): array
    {
        return ['score' => 'integer', 'total_items' => 'integer', 'percentage' => 'decimal:2', 'completed_at' => 'datetime'];
    }

    public function facultyProfile(): BelongsTo
    {
        return $this->belongsTo(FacultyProfile::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SkillsAssessmentAnswer::class, 'attempt_id');
    }
}
