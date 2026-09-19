<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalAnalysis extends Model
{
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'technologies' => 'array',
            'identified_expertise_areas' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function researchProposal(): BelongsTo
    {
        return $this->belongsTo(ResearchProposal::class);
    }
}
