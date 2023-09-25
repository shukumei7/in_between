<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Snapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'action_id',
        'pot',
        'dealer',
        'turn',
        'dealt',
        'discards',
        'players',
        'previous',
        'hands',
        'pots',
        'scores'
    ];

    protected $casts = [
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime'
    ];

}
