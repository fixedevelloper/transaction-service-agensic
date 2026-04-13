<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gateway extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'method',
        'type',
        'bank_id',
        'payer_code_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * 🔗 Relations
     */
    public function payerCode()
    {
        return $this->belongsTo(PayerCode::class);
    }

    /**
     * 🔍 Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByMethod($query, $method)
    {
        return $query->where('method', $method);
    }

    public function scopeByPayerCode($query, $countryId)
    {
        return $query->where('payer_code_id', $countryId);
    }

    /**
     * 🧠 Helpers métier
     */
    public function isMobileMoney()
    {
        return $this->type === self::TYPE_MOBILE_MONEY;
    }

    public function isBank()
    {
        return $this->type === self::TYPE_BANK;
    }

    public function isUssd()
    {
        return $this->method === self::METHOD_USSD;
    }

    public function isApi()
    {
        return $this->method === self::METHOD_API;
    }

    /**
     * 🔐 Constantes
     */
    const METHOD_API = 'api';
    const METHOD_USSD = 'ussd';
    const METHOD_MANUAL = 'manual';

    const TYPE_MOBILE_MONEY = 'mobile_money';
    const TYPE_BANK = 'bank';
    const TYPE_CARD = 'card';
}
