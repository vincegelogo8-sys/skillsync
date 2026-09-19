<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillsAssessmentAnswer extends Model
{
    protected $hidden = ['correct_answer'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SkillsAssessmentAttempt::class, 'attempt_id');
    }
}
