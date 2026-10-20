<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePerm extends Model
{
    protected $fillable = ['role_key', 'module', 'action', 'allowed'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }
}
