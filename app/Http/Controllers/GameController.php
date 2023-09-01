<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameController extends Controller
{
    public function play() {
        if(false === $room_id = $this->__getUserRoom()) {
            return response()->json(['message' => 'User not logged in'], 302);
        }
        if($room_id == 0) {
            return $this->__listAvailableRooms();
        }
    }

    private function __getUserRoom($user_id = null) {
        if($user_id === null && empty($user = Auth::user())) {
            return false;
        }
        if($user_id && empty($user = User::find($user_id))) {
            return false;
        }
        return $user->getRoomID();
    }

    private function __listAvailableRooms() {
        if(empty($rooms = Room::select('uuid', 'name')->orderBy('created_at', 'asc')->get()->toArray())) {
            return $this->__createRoom();
        }
        return response()->json(['message' => 'Listing rooms available', 'rooms' => $rooms], 200);
    }

    private function __getRoomStatus() {
        if(empty($room_id = $this->__getUserRoom())) { // shouldn't happen
            return $this->__listAvailableRooms();
        }
        $room = Room::find($room_id);
        if(!$room->analyze()) {
            return response()->json(['message' => 'Failed to get room status'], 302);
        }
        $status = $room->getStatus();
        if(count($status['players']) <= 1) $message = 'Waiting for more players';
        return response()->json(compact('message') + $status, 200);
    }

    private function __addAction($command, $room_id, $data = []) {
        $action = new Action;
        $action->timestamps = false;
        $action->room_id = $room_id;
        $action->action = $command;
        foreach($data as $key => $value) {
            $action->$key = $value;
        }
        return $action->save();
    }

    private function __createRoom() {
        $user = Auth::user();
        $room = Room::factory()->create(['user_id' => $user->id]);
        $this->__addAction('join', $room->id, ['user_id' => $user->id]);
        return $this->__getRoomStatus();
    }
}
