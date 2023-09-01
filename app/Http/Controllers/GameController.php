<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameController extends Controller
{
    public function status() {
        if(false === $room_id = $this->__getUserRoomID()) {
            return response()->json(['message' => 'User not logged in'], 302);
        }
        if($room_id == 0) {
            return $this->__joinRandomRoom();
        }
        return $this->__getRoomStatus();
    }

    public function play(Request $request) {
        if(false === $room_id = $this->__getUserRoomID()) {
            return response()->json(['message' => 'User not logged in'], 302);
        }
        if($room_id == 0) {
            return $this->__joinRandomRoom();
        }
        
    }

    private function __getUserRoomID($user_id = null) {
        if($user_id === null && empty($user = Auth::user())) {
            return false;
        }
        if($user_id && empty($user = User::find($user_id))) {
            return false;
        }
        return $user->getRoomID();
    }

    private function __joinRandomRoom() {
        $rooms = Room::orderBy('created_at', 'asc')->get();
        if($rooms->isEmpty()) {
            return $this->__createRoom();
        }
        foreach($rooms as $room) {
            if($return = $this->__joinRoom($room)) {
                return $return;
            }
        }
        return response()->json(['message' => 'Listing rooms available', 'rooms' => $rooms->toArray()], 200);
    }

    private function __joinRoom($room) {
        $status = $room->analyze();
        if($room->isFull()) {
            dd('Full: '.number_format($room->max_players).' : '.count($status['players']));
            return false;
        }
        if($room->isLocked()) {
            dd('Locked: '.$room->passcode);
            return false;
        }
        $user = Auth::user();
        Action::add('join', $room->id, ['user_id' => $user->id]);
        if(count($status['players']) == 1) {
            return $this->__startGame($room);
        }
        return $this->__getRoomStatus(true);
    }

    private function __startGame($room) {
        $status = $room->analyze(true);
        foreach($status['players'] as $user_id) {
            Action::add('pot', $room->id, ['user_id' => $user_id, 'bet' => -1* $room->pot]);
        }
        return $this->__startRound($room, $status);
    }

    private function __startRound($room, $status = null) {
        empty($status) && $status = $room->analyze(true);
        $count = count($status['players']);
        $t = $status['dealer'] + 1;
        Action::add('shuffle', $room->id);
        for($x = 0; $x < $count * 2; $x++) {
            if($t >= $count) $t = 0;
            Action::add('deal', $room->id, ['user_id' => $status['players'][$t++], 'card' => $room->dealCard()]);
        }
        return $this->__getRoomStatus(true);
    }

    private function __getRoomStatus($refresh = false) {
        if(empty($room_id = $this->__getUserRoomID())) { // shouldn't happen
            return $this->__joinRandomRoom();
        }
        $room = Room::find($room_id);
        if(empty($status = $room->analyze($refresh))) {
            return response()->json(['message' => 'Failed to get room status'], 302);
        }
        $user = Auth::user();
        if(count($status['players']) <= 1) {
            $message = 'Waiting for more players';
        } else if($status['current'] != $user->id) {
            $message = 'Waiting for '.User::find($status['current'])->name;
        } else if($status['current'] == $user->id) {
            $message = 'It is your turn!';
        }
        !isset($status['hands']) && $status['hands'] = $room->getHands($user->id);
        return response()->json(compact('message') + $status, 200);
    }

    private function __createRoom() {
        $user = Auth::user();
        $room = Room::factory()->create(['user_id' => $user->id]);
        Action::add('join', $room->id, ['user_id' => $user->id]);
        return $this->__getRoomStatus();
    }
}
