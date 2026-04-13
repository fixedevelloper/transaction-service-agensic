<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,

            // 🔗 URL dynamique
            'payment_url' => url("/pay/{$this->code}"),

            'name' => $this->name,

            // 💰 cast propre pour Kotlin Double
            'amount' => (double) $this->amount,
            'fees' => (double) $this->fees,

            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'description' => $this->description,

            'status' => $this->status,

            'provider' => $this->provider,
            'provider_token' => $this->provider_token,

            'payment_method' => $this->payment_method,
            'channel' => $this->channel,

            'reference' => $this->reference,
            'retry_count' => $this->retry_count,

            // ✅ JSON → Object Kotlin
            'sender' => $this->sender ? json_decode($this->sender, true) : null,
            'customer' => $this->customer ? json_decode($this->customer, true) : null,

            // ✅ Map<String, Any>
            'metadata' => $this->metadata ? json_decode($this->metadata, true) : [],

            'submitted_at' => optional($this->submitted_at)->toDateTimeString(),
            'expires_at' => optional($this->expires_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
