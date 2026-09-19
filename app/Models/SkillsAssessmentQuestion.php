<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillsAssessmentQuestion extends Model
{
    public const OPTIONS = ['a', 'b', 'c', 'd'];

    protected $fillable = ['question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'category'];

    protected $hidden = ['correct_answer'];
}
