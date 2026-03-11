<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'desc',
        'time',
        'cat',
        'thumb',
        'thumb_emoji',
        'active',
        'stage',
        'progress',
        'enrolled',
        'completed',
        'time_spent',
        'modules',    // ← was missing — modules never persisted
        'companies',  // ← was missing — company assignments via JSON field
    ];

    protected $casts = [
        'active'    => 'boolean',
        'enrolled'  => 'boolean',
        'completed' => 'boolean',
        'modules'   => 'array',   // ← was missing — stored/retrieved as JSON
        'companies' => 'array',   // ← was missing — stored/retrieved as JSON
    ];

    /**
     * Companies that have been assigned this course.
     * Pivot table: company_course (matches your migration).
     */
    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_course')
                    ->withPivot('assigned_at')
                    ->withTimestamps();
    }
}
