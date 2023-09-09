<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Action extends Model
{
    use HasFactory;

    protected $casts = [
        'time'    => 'datetime'
    ];

    public static function add($action, $room_id, $data = []) {
        $model = new self;
        $model->timestamps = false;
        $model->room_id = $room_id;
        $model->action = $action;
        foreach($data as $key => $value) {
            $model->$key = $value;
        }
        $model->save();
        return $model;
    }
}
