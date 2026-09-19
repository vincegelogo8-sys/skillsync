<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdviserRequest extends Model
{
    public const ACTIVE_STATUSES = ['pending', 'approved'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'responded_at' => 'datetime', 'active_slot' => 'integer'];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function facultyProfile(): BelongsTo
    {
        return $this->belongsTo(FacultyProfile::class);
    }

    public function researchProposal(): BelongsTo
    {
        return $this->belongsTo(ResearchProposal::class);
    }
}
