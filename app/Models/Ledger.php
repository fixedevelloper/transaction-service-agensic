<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Ledger extends Model
{
    use HasFactory;

    protected $table = 'ledger';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
