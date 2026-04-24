<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sender extends Model
{
    use HasFactory;

protected $fillable = [
    'user_id', 'name', 'account_type', 'phone', 'email', 'country', 
    'address', 'business_name', 'business_type','code', 
    'business_register_date', 'gender', 'date_birth', 
    'identification_number', 'identification_type', 'identification_expired'
];

    // Un sender peut avoir plusieurs transactions envoyées
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
