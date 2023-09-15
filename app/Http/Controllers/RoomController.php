<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Room;

class RoomController extends Controller
{
    public function check($name) {
        if(strlen($name = trim($name)) < 3) {
            return response()->json(['message' => 'Your room name needs at least 3 characters', 'available' => false]);
        }
        if(Room::where('name', $name)->first()) {
            return response()->json(['message' => 'Your room name is taken', 'available' => false]);
        }
        return response()->json(['message' => 'Your room name is available', 'available' => true]);
    }
}
