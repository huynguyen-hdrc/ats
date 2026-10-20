<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'label', 'base', 'sort', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'sort' => 'integer'];
    }

    public function perms(): HasMany
    {
        return $this->hasMany(RolePerm::class, 'role_key', 'key');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_key', 'key');
    }
}
