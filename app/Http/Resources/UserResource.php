<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role_key' => $this->role_key,
            'base' => $this->base(),
            'status' => $this->status,
            'employee_id' => $this->employee_id,
            'two_factor' => $this->hasTwoFactor(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
