<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'used' => (bool) $this->used,
            'expiresAt' => $this->expires_at?->toISOString(),
            'createdBy' => (string) $this->created_by,
            'usedBy' => $this->used_by ? (string) $this->used_by : null,
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => (string) $this->creator->id,
                    'name' => trim(($this->creator->first_name ?? '') . ' ' . ($this->creator->last_name ?? '')) ?: null,
                    'email' => $this->creator->email,
                ];
            }),
            'usedByUser' => $this->whenLoaded('usedBy', function () {
                if (!$this->usedBy) return null;
                return [
                    'id' => (string) $this->usedBy->id,
                    'name' => trim(($this->usedBy->first_name ?? '') . ' ' . ($this->usedBy->last_name ?? '')) ?: null,
                    'email' => $this->usedBy->email,
                ];
            }),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
