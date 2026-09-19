<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResearchProposal extends Model
{
    protected $hidden = ['file_path'];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ProposalAnalysis::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(AdviserAssignment::class);
    }
}
