<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sender extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'country',
        'address',
        'identification_number',
        'identification_type',
        'identification_expired',
        'status',
    ];

    // Un sender peut avoir plusieurs transactions envoyées
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
