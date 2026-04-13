<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    use HasFactory;

    protected $table = 'operators';

    protected $fillable = [
        'name', 'logo', 'status', 'country_id'
    ];

    // Relation : un opérateur appartient à un pays
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    // Relation : un opérateur peut avoir plusieurs dépôts
    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }
}
