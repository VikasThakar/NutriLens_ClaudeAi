<?php

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiKey
 *
 * Note what is absent: `key_hash` never appears here, and there is no field
 * that could reconstruct the key. `key_prefix` is the only part of the secret
 * that is ever shown again, and it exists so the owner can tell two keys apart.
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'key_prefix' => $this->key_prefix,
            'abilities' => $this->abilities ?? [],
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
