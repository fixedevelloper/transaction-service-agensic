<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'phone',
        'bank_account',
        'mobile_wallet',
        'country',
        'city',
        'address',
        'identification_number',
        'identification_type',
        'identification_expired',
        'status',
    ];

    // Un bénéficiaire peut recevoir plusieurs transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
