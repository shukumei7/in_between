<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Action;

class Room extends Model
{
    use HasFactory;

    private $__deck = 52;
    private $__dealt = [];
    private $__discards = [];
    private $__players = [];
    private $__pot = 0;
    private $__dealer = 0;
    private $__turn = 0;
}
