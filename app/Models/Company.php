<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'industry',
        'contact_email',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Users that belong to this company.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Courses assigned to this company.
     */
    public function courses()
    {
        return $this->belongsToMany(Course::class, 'company_course')
                    ->withPivot('assigned_at')
                    ->withTimestamps();
    }
}
