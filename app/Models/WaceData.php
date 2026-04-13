<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaceData extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'service',
        'type',
    ];

    /**
     * 🔍 Scopes utiles
     */
    public function scopeByService($query, $service)
    {
        return $query->where('service', $service);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}
