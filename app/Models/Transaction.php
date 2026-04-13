<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'beneficiary_id',
        'amount',
        'type',
        'status',
        'reference',
        'currency',
        'note',
        'initiated_by',
    ];

    // Relations
    public function sender()
    {
        return $this->belongsTo(Sender::class);
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(Ledger::class);
    }

    // Génération automatique de référence unique
    protected static function booted()
    {
        static::creating(function ($transaction) {
            $transaction->reference = 'TRX-' . strtoupper(uniqid());
        });
    }
}
