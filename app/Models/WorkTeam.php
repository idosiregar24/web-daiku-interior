<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 32 — a "Tim Tukang" template: its members [{name, level, weight,
 * company}], picked per project (ProjectWageSplit). Edited through
 * WageSplitService.
 */
class WorkTeam extends Model
{
    protected $fillable = ['name', 'leader_name', 'members', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'members' => 'array',
        ];
    }
}
