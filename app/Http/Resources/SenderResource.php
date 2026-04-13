<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SenderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Infos principales
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,

            // Adresse
            'country' => $this->country,
            'address' => $this->address,

            // Identification (KYC)
            'identification' => [
                'number' => $this->identification_number,
                'type' => $this->identification_type,
                'expired_at' => $this->identification_expired,
            ],

            // Status
            'status' => $this->status,

            // Dates
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
