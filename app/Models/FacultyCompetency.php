<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyCompetency extends Model
{
    public const DIMENSIONS = [
        'system_analysis' => 'System Analysis',
        'research_methodology' => 'Research Methodology',
        'research_documentation' => 'Research Documentation',
        'data_analysis' => 'Data Analysis',
        'system_evaluation' => 'System Evaluation',
    ];

    public const RUBRIC = [
        1 => 'Limited', 2 => 'Developing', 3 => 'Competent',
        4 => 'Highly Competent', 5 => 'Advanced',
    ];

    protected $fillable = [
        'system_analysis', 'research_methodology', 'research_documentation',
        'data_analysis', 'system_evaluation', 'remarks',
    ];

    protected function casts(): array
    {
        return array_fill_keys(array_keys(self::DIMENSIONS), 'integer');
    }

    public function facultyProfile(): BelongsTo
    {
        return $this->belongsTo(FacultyProfile::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
