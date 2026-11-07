<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResearchProposal extends Model
{
    public const OTHER_STUDENT_DOCUMENT_MESSAGE = 'This proposal document has already been submitted by another student. Each proposal must be unique, so please upload your own proposal.';

    public const OTHER_STUDENT_TITLE_MESSAGE = 'A proposal with this title has already been submitted by another student. Please use your own proposal title.';

    protected $hidden = ['file_path'];

    /**
     * True when a different student already has a proposal with the same file or title fingerprint.
     *
     * @param  'file_hash'|'title_key'  $column
     */
    public static function submittedByAnotherStudent(StudentProfile $profile, string $column, string $value): bool
    {
        if (! in_array($column, ['file_hash', 'title_key'], true) || $value === '') {
            return false;
        }

        return static::where($column, $value)
            ->where('student_profile_id', '!=', $profile->id)
            ->exists();
    }

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