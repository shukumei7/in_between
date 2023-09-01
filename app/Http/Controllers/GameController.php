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
        if(empty($latest_leave = Action::where('user_id', $user->id)->where('action', 'leave')->orderBy('time', 'desc')->first())) {
            return !empty($latest_join = Action::where('user_id', $user->id)->where('action', 'join')->orderBy('time', 'desc')->first())? $latest_join->room_id : 0;
        }
        return !empty($latest_join = Action::where('user_id', $user->id)->where('action', 'join')->where('time', '>', $latest_leave->time)->orderBy('time', 'desc')->first())? $latest_join->room_id : 0;
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
        if(empty($actions = Action::where('room_id', $room_id)->orderBy('time', 'asc')->get()->toArray())) {
            Room::delete($room_id); // delete empty room, shouldn't happen
            return $this->__listAvailableRooms();
        }
        $players = [];
        foreach($actions as $action) {
            switch($action['action']) {
                case 'join':
                    $players[$action['user_id']] = $action['user_id'];
                    break;
                case 'leave':
                    unset($players[$action['user_id']]);
                    break;
            }
        }
        return array_values($players);
    }

    private function __createRoom() {
        $room = Room::factory()->create();
        $user = Auth::user();
        $action = new Action;
        $action->room_id = $room->id;
        $action->user_id = $user->id;
        $action->action = 'join';
        $action->save();
        return $this->__getRoomStatus();
    }
}
