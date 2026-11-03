<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    protected function casts(): array
    {
        return [
            'cosine_similarity_score' => 'decimal:8',
            'multi_expertise_score' => 'decimal:8',
            'topic_alignment_score' => 'decimal:8',
            'final_score' => 'decimal:8',
            'details' => 'array',
        ];
    }

    public function researchProposal(): BelongsTo
    {
        return $this->belongsTo(ResearchProposal::class);
    }

    public function facultyProfile(): BelongsTo
    {
        return $this->belongsTo(FacultyProfile::class);
    }
}
