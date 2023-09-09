<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameController extends Controller
{

    private $__user = null;
    private $__room = null;
    private $__status = [];

    public function status() {
        $this->__clearData();
        if(false === $room = $this->__getUserRoom()) {
            return response()->json(['message' => 'User not logged in'], 401);
        }
        if(is_numeric($room) && $room == 0) {
            return $this->__listRooms();
        }
        return $this->__getRoomStatus();
    }

    public function spectate($id) {
        if(empty($room = Room::find($id))) {
            return response()->json(['message' => 'Room ID not found'], 302);
        }
        if(empty($status = $room->analyze())) {
            return response()->json(['message' => 'Cannot get Room info'], 302);
        }
        $message  = '';
        if(count($status['players']) < 2) {
            $message = 'Waiting for more players';
        } else if(!empty($status['current'])) {
            $message = 'Waiting for '.User::find($status['current'])->name;
        }
        return response()->json(['message' => 'You are spectating. '.$message] + $status, 200);
    }

    public function play(Request $request) {
        $this->__clearData();
        if(true !== $return = $this->__checkUserRoom($request)) {
            return $return;
        }
        $user = $this->__user;
        $room = $this->__room;
        $this->__status = $status = $room->analyze();
        if(empty($request->action)) {
            return response()->json(['message' => 'An action is required'] + $status + $this->__getUserStatus($status), 302);
        }
        if($request->action == 'leave') {
            return $this->__leaveRoom();
        }
        if($request->action == 'create') {
            return $this->__returnAlreadyInRoom();
        }
        if(count($status['players']) < 2) {
            return response()->json(['message' => 'Waiting for more players'] + $status + $this->__getUserStatus($status), 302);
        }
        if($status['current'] != $user->id) {
            return response()->json(['message' => 'It is not your turn'] + $status + $this->__getUserStatus($status), 200);
        }
        switch($request->action) {
            case 'play':
                return $this->__playHand($request);
            case 'pass':
                return $this->__passHand();
        }
        return response()->json(['message' => 'You made an invalid action'] + $status + $this->__getUserStatus($status), 302);
    }

    public function join($id) {
        if(empty($user = Auth::user())) {
            return response()->json(['message' => 'User not logged in'], 401);
        }
        if($user->getRoomID()) {
            return $this->__returnAlreadyInRoom();
        }
        if(empty($id) || empty($room = Room::find($id))) {
            return response()->json(['message' => 'Room ID not found'], 302);
        }
        return $this->__joinRoom($room, !empty($request->passcode) ? $request->passcode : null);
    }

    private function __returnAlreadyInRoom() {
        return response()->json(['message' => 'You are already in a room'], 302);
    }

    private function __clearData() {
        $this->__room = $this->__user = $this->__status = null;
    }

    private function __listRooms() {
        $rooms = Room::select('name')->orderBy('created_at', 'asc')->get()->toArray();
        return response()->json(['message' => 'Listing all rooms', 'rooms' => $rooms], 200);
    }

    private function __checkUserRoom($request = null) {
        if($this->__room) {
            return true;
        }
        if(false === $room = $this->__getUserRoom()) {
            return response()->json(['message' => 'User not logged in'], 302);
        }
        if(is_numeric($room) && $room == 0) {
            return $this->__processJoining($request);
        }
        return true;
    }

    private function __processJoining($request) {
        if(empty($request->action) || $request->action != 'create') {
            return $this->__joinRandomRoom();
        }
        $user = $this->__user ?: Auth::user();
        $settings = ['name' => $user->name."'s room"];
        $keywords = ['name', 'passcode', 'max_players', 'pot'];
        foreach($keywords as $keyword) {
            !empty($request->$keyword) && $this->__addRoomSetting($settings, $keyword, $request->$keyword);
        }
        return $this->__createRoom($settings);
    }

    private function __addRoomSetting(&$settings, $setting, $value) {
        if($setting == 'pot' && (!is_numeric($value) || $value < RESTRICT_BET)) return;
        if($setting == 'max_players' && (!is_numeric($value) || $value < 2 || $value > MAX_PLAYERS)) return;
        $settings[$setting] = $value;
    }

    private function __getUserRoom() {
        if(empty($user = Auth::user())) {
            return false;
        }
        // var_dump('Load User '.$user->id);
        $this->__user = $user;
        if(0 == $room_id = $user->getRoomID()) {
            return 0;
        }
        return $this->__room = $room = Room::find($room_id);
    }

    private function __joinRandomRoom() {
        $rooms = Room::where('passcode', '=', '')->orWhereNull('passcode')->orderBy('created_at', 'asc')->get();
        if($rooms->isEmpty()) {
            return $this->__createRoom();
        }
        $user = $this->__user ?: Auth::user();
        foreach($rooms as $room) {
            if(($return = $this->__joinRoom($room)) && $return->getStatusCode() == 200 && ($status = json_to_array($return)) && !empty($status['players']) && !empty($status['players'][$user->id])) {
                return $return;
            }
        }
        return $this->__createRoom();
    }

    private function __joinRoom($room, $passcode = null) {
        if(empty($room) || empty($room->id)) {
            return response()->json(['message' => 'Invalid room'], 302);
        }
        $status = $room->analyze();
        if($room->isFull()) {
            return response()->json(['message' => 'Room is full!'], 302);
        }
        if($room->isLocked() && $passcode != $room->passcode) {
            return response()->json(['message' => 'You need a proper passcode to enter'], 302);
        }
        Action::add('join', $room->id, ['user_id' => $this->__user->id]);
        $this->__room = $room;
        $this->__user->points_updated_at = 0;
        $message = 'You joined Room '.$status['room_name'].' ('.$status['room_id'].')';
        if(count($status['players']) == 1) {
            return $this->__startGame($message);
        }
        return $this->__getRoomStatus($message);
    }

    private function __startGame($message = null) {
        $room = $this->__room;
        $status = $room->analyze(true);
        $this->__getPots(array_keys($status['players']));
        Action::add('shuffle', $room->id);
        return $this->__startRound($message);
    }

    private function __getPots($players) {
        $room = $this->__room;
        foreach($players as $user_id) {
            Action::add('pot', $room->id, ['user_id' => $user_id, 'bet' => -1* $room->pot]);
        }
        // var_dump('Old time: '.$room->updated_at);
        sleep(1);
        $room->updated_at = now(); // force update of points
        $room->save();
        $this->__room = $room;
        // var_dump('New time: '.$this->__room->updated_at);
    }

    private function __startRound($message  = null) {
        $room = $this->__room;
        $status = $room->analyze(true);
        // var_dump($status['dealer']);
        $count = count($players = $status['playing']);
        $t = array_search($status['dealer'], $players) + 1;
        // var_dump('First Draw: '.$t.' vs '.$count);
        for($x = 0; $x < $count * 2; $x++) {
            if($t >= $count) $t = 0;
            Action::add('deal', $room->id, ['user_id' => $players[$t++], 'card' => $room->dealCard()]);
        }
        return $this->__getRoomStatus(($message ? $message.'. ' : '').'New round started!');
    }

    private function __getRoomStatus($refresh = false) {
        if($this->__status && !$refresh) {
            return response()->json($this->__status + $this->__getUserStatus($status), 200);
        }
        if(true !== $return = $this->__checkUserRoom()) {
            return $return;
        }
        $room = $this->__room;
        if(empty($status = $room->analyze($refresh))) {
            return response()->json(['message' => 'Failed to get room status'], 302);
        }
        $user = $this->__user;
        if(is_string($refresh)) {
            $message = $refresh;
        } else if(count($status['players']) <= 1) {
            $message = 'Waiting for more players';
        } else if($status['current'] != $user->id) {
            $message = 'Waiting for '.User::find($status['current'])->name;
        } else if($status['current'] == $user->id) {
            $message = 'It is your turn!';
        }
        return response()->json(compact('message') + $status + $this->__getUserStatus($status), 200);
    }

    private function __getUserStatus($status) {
        $user = $this->__user;
        $room = $this->__room;
        $out = [
            'user_id'   => $user->id,
            'hand'      => $room->getHand($user->id)
        ];
        if(empty($user->points_updated_at) || $user->points_updated_at < $room->updated_at) {
            $out['points'] = $user->getPoints();
            $user->points_updated_at = now();
            $user->save();
            $this->__user = $user;
        }
        return $out;
    }

    private function __createRoom($settings = []) {
        $user = $this->__user ?: Auth::user();
        $created = !empty($settings);
        if(!empty($settings['name']) && Room::where('name', $settings['name'])->first()) {
            return response()->json(['message' => 'That Room name already exists: '.$settings['name']], 302);
        }
        $settings += ['user_id' => $user->id, 'name' => ucwords(fake()->unique()->word).' '.Room::count()];
        // var_dump($settings);
        $this->__room = $room = Room::create($settings);
        Action::add('join', $room->id, ['user_id' => $user->id]);
        return $this->__getRoomStatus($created ? 'You created a room' : 'You joined Room '.$room->name.' ('.$room->id.')');
    }

    private function __playHand($request) {
        if(true != $return = $this->__checkUserRoom()) {
            return $return;
        }
        $room = $this->__room;
        if($room->pot > 0 && (empty($request->bet) || !is_numeric($request->bet) || $request->bet < 1)) {
            return response()->json(['message' => 'You need to place a bet of at least 1'], 302);
        }
        $bet = $request->bet;
        $status = $this->__status ?: $room->analyze();
        if($room->pot > 0 && $bet > $status['pot']) {
            return response()->json(['message' => 'You cannot bet more than the pot of '.number_format($status['pot'])] + $status, 302);
        }
        $points = 0;
        $user = $this->__user;
        if($room->pot > 0 && ($points = $user->getPoints()) > RESTRICT_BET && $bet > $points) {
            return response()->json(['message' => 'You cannot bet more than your points of '.number_format($points), 'points' => $points], 302);
        }
        if($room->pot > 0 && $points < 1 && $bet > RESTRICT_BET) {
            return response()->json(['message' => 'You can only bet a max of '.number_format(RESTRICT_BET).' if your points are less than 1', 'points' => $points], 302);
        }
        $hand = $room->getHand($user_id = $user->id);
        $card = $room->dealCard();
        if(intval($card) < intval(min($hand)) || intval($card) > intval(max($hand))) {
            $bet *= -1; // lose
        }
        Action::add('play', $room->id, compact('user_id', 'bet', 'card'));
        if($status['pot'] == $bet) { // winning means clearing pot
            $this->__getPots($status['playing']); // make playing pay
        }
        $hand []= $card;
        $output = ['message' => 'You '.($bet > 0? 'win' : 'lose').' '.number_format($b = abs($bet)).' point'.($b == 1? '' : 's')] + ['cards' => $hand, 'points' => $user->getPoints(true)];
        return $this->__checkEndRound($output, $status, true);
    }

    private function __passHand() {
        if(true != $return = $this->__checkUserRoom()) {
            return $return;
        }
        Action::add('pass', $this->__room->id, [ 'user_id' => $this->__user->id]);
        $output = ['message' => 'You passed'];
        return $this->__checkEndRound($output, $this->__room->analyze(), true);
    }

    private function __checkEndRound($output, $status, $refresh = false) {
        if($status['dealer'] != $status['current']) { // not end round
            return response()->json($output + ($refresh ? $this->__room->analyze(true) : $status) + $this->__getUserStatus($status), 200);
        }
        return $this->__cleanupRound($output, $status);
    }

    private function __cleanupRound($output, $status, $rotate = true) {
        // end of round
        // check if new players came in
        if($new_players = array_diff(array_keys($status['players']), $status['playing'])) {
            $this->__getPots($new_players);
        }
        // check if deck has enough cards for all players
        if($status['deck'] < count($status['players']) * 3) {
            Action::add('shuffle', $status['room_id']);
        }
        $rotate && Action::add('rotate', $status['room_id']);
        if(isset($output['points'])) {
            $this->__user->points_updated_at = 0; // get points again at end
        }
        return $this->__startRound($output['message']);
    }

    private function __leaveRoom() {
        $room = $this->__room;
        Action::add('leave', $room->id, ['user_id' => $user_id = $this->__user->id]);
        $status = $room->analyze();
        if($status['current'] == $status['dealer'] && $status['current'] == $user_id) {
            $this->__cleanupRound(['message' => ''], $status);
        }
        return response()->json([
            'message'   => 'You left the room', 
            'room_id'   => 0,
            'deck'      => 0,
            'hidden'    => 0,
            'dealer'    => 0,
            'current'   => 0,
            'discards'  => [],
            'hand'      => [],
            'hands'     => [],
            'players'   => [],
            'playing'   => [],
            'activities'=> [],
            'room_name' => 'none'
        ], 200);
    }
}
