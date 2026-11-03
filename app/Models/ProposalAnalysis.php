<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalAnalysis extends Model
{
    protected $attributes = ['analysis_status' => 'pending'];

    public const CURRENT_VERSION = 'expertise-match-v2';

    public function hasCurrentAnalysis(): bool
    {
        return $this->analysis_status === 'completed'
            && $this->analyzed_at !== null
            && $this->analysis_version === self::CURRENT_VERSION
            && filled($this->research_representation);
    }

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
