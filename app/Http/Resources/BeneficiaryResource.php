<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeneficiaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,

            // Infos
            'name' => $this->name,
            'phone' => $this->phone,

            // Paiement
            'bank_account' => $this->bank_account,
            'mobile_wallet' => $this->mobile_wallet,

            // Localisation
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->address,

            // KYC
            'identification' => [
                'number' => $this->identification_number,
                'type' => $this->identification_type,
                'expired_at' => $this->identification_expired,
            ],

            'status' => $this->status,

            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
