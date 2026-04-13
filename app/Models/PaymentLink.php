<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentLink extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'user_id',
        'amount',
        'fees',
        'country_code',
        'currency',
        'name',
        'description',

        'sender',
        'customer',

        'status',
        'submitted_at',
        'expires_at',
        'cancelled_at',

        'provider',
        'provider_token',
        'payment_method',
        'channel',

        'reference',
        'metadata',

        'secure_token',
        'retry_count',
    ];

    protected $appends = ['payment_url'];
    /**
     * CASTS
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',

        'sender' => 'array',
        'customer' => 'array',
        'metadata' => 'array',

        'submitted_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * STATUSES
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * SCOPES
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    /**
     * CHECK EXPIRATION
     */
    public function isExpired(): bool
    {
        return $this->expires_at && now()->gt($this->expires_at);
    }

    /**
     * CHECK STATUS
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * MARK AS PAID
     */
    public function markAsPaid(string $providerToken = null): void
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'submitted_at' => now(),
            'provider_token' => $providerToken,
        ]);
    }

    /**
     * MARK AS FAILED
     */
    public function markAsFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
        ]);
    }

    /**
     * MARK AS EXPIRED
     */
    public function markAsExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    /**
     * GENERATE FULL URL (helper frontend)
     */
    public function getPaymentUrlAttribute(): string
    {
       // return url("/pay/" . $this->code);
        return env('FRONTEND_URL')."/pay/" . $this->code;
    }

    /**
     * INCREMENT RETRY
     */
    public function incrementRetry(): void
    {
        $this->increment('retry_count');
    }
}
