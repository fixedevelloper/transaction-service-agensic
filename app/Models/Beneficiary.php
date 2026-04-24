<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Beneficiary extends Model
{
    use HasFactory;

protected $fillable = [
    'user_id', 'name', 'account_type', 'business_name', 'business_type', 
    'business_register_date', 'date_birth', 'phone', 'bank_account', 
    'mobile_wallet', 'country', 'city', 'address', 'code',
    'identification_number', 'identification_type', 'identification_expired'
];

    // Un bénéficiaire peut recevoir plusieurs transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
