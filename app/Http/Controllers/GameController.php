<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameController extends Controller
{

    private $__user = null;
    private $__room = null;
    private $__playedCard = null;
    private $__status = [];
    private $__blank = [
        'room_id'   => 0,
        'pot'       => 0,
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
    ];

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
        return response()->json(['message' => 'You are spectating. '.$message] + $status + $this->__getRoomSettings($room), 200);
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
            return $this->leaveRoom($user);
        }
        if($request->action == 'create') {
            return $this->__returnAlreadyInRoom($room->id);
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
        if($room_id = $user->getRoomID()) {
            return $this->__returnAlreadyInRoom($room_id);
        }
        if(empty($id) || empty($room = Room::find($id))) {
            return response()->json(['message' => 'Room ID not found'], 302);
        }
        return $this->joinRoom($user, $room, !empty(request()->passcode) ? request()->passcode : null);
    }

    private function __returnAlreadyInRoom($room_id) {
        return response()->json(['message' => 'You are already in a room', 'room_id' => $room_id], 302);
    }

    public function clearData() {
        $this->__room = $this->__user = $this->__status = null;
    }

    private function __clearData() {
        $this->__room = $this->__user = $this->__status = null;
    }

    private function __listRooms() {
        $rooms = Room::select('id', 'name')->orderBy('created_at', 'asc')->get()->toArray();
        return response()->json(['message' => 'Listing all rooms', 'rooms' => array_map(function($room) { $room['passcode'] = !empty($room['passcode']); return $room; }, $rooms)] + $this->__blank, 200);
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
        while($room = $rooms[$index = rand(0, count($rooms) - 1)]) {
            if(($return = $this->joinRoom($user, $room)) && $return->getStatusCode() == 200 && ($status = json_to_array($return)) && !empty($status['players']) && !empty($status['players'][$user->id])) {
                return $return;
            }
            unset($rooms[$index]);
        }
        return $this->__createRoom();
    }

    public function joinRoom($user, $room, $passcode = null) {
        if(empty($room) || empty($room->id)) {
            return response()->json(['message' => 'Invalid room'], 302);
        }
        $status = $room->analyze(true);
        if($room->isFull()) {
            return response()->json(['message' => 'Room is full!'], 302);
        }
        if($room->isLocked() && $passcode != $room->passcode) {
            return response()->json(['message' => 'You need a proper passcode to enter'], 302);
        }
        Action::add('join', $room->id, ['user_id' => $user->id]);
        $this->__room = $room;
        $message = 'You joined Room '.$status['room_name'].' ('.$status['room_id'].')';
        if(count($status['players']) == 1) {
            // dump('User '.$user->id.' activates game');
            return $this->__startGame($message);
        }
        return $this->__getRoomStatus($message);
    }

    private function __startGame($message = null) {
        $room = $this->__room;
        $status = $room->analyze(true);
        $this->__getPots($room, $this->__getNewPlayers($status));
        Action::add('shuffle', $room->id);
        // dump('Shuffle for new game');
        return $this->__startRound($room, $message);
    }

    private function __getPots($room, $players) {
        // dump('Add pot');
        foreach($players as $user_id) {
            if(!$user_id) {
                dd(compact('players', 'user_id'));
            }
            Action::add('pot', $room->id, ['user_id' => $user_id, 'bet' => -1 * $room->pot]);
        }
        $this->__room = $room;
    }

    private function __startRound($room, $message  = null) {
        $status = $room->analyze(true);
        // var_dump($status['dealer']);
        $count = count($players = $status['playing']);
        $t = array_search($status['dealer'], $players) + 1;
        // var_dump('First Draw: '.$t.' vs '.$count);
        for($x = 0; $x < $count * 2; $x++) {
            if($t >= $count) $t = 0;
            Action::add('deal', $room->id, ['user_id' => $players[$t++], 'card' => $room->dealCard()]);
        }
        // dump('Dealt Cards');
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
        } else if(count($status['players']) <= 1 || empty($status['current'])) {
            $message = 'Waiting for more players';
        } else if($status['current'] != $user->id) {
            $message = 'Waiting for '.User::find($status['current'])->name;
        } else if($status['current'] == $user->id) {
            $message = 'It is your turn!';
        }
        
        return response()->json(compact('message') + $status + $this->__getRoomSettings($room) + $this->__getUserStatus($status), 200);
    }

    private function __getRoomSettings($room) {
        return ['settings' => [
            'max_players'   => $room->max_players,
            'pot_size'      => $room->pot,
            'secured'       => !empty($room->passcode)
        ]];
    }

    private function __getUserStatus($status) {
        if(empty($user = $this->__user ?: Auth::user())) {
            return [];
        }
        $out = [
            'user_id'   => $user->id,
            'points'    => $user->getPoints(true)
        ];
        if($this->__playedCard) {
            $out['card'] = $this->__playedCard;
        }
        if(empty($status['room_id']) || $status['room_id'] != $user->getRoomID() || empty($room = $this->__room ?: Room::find($status['room_id']))) {
            return $out;
        }
        if(in_array($user->id, $status['playing'])) {
            $out += [
                'hand'  => $room->getHand($user->id)
            ];
        };
        return $out;
    }

    public function create(Request $request) {
        return $this->__createRoom([
            'name'          => $request->name,
            'passcode'      => $request->passcode,
            'max_players'   => $request->max_players,
            'pot'           => $request->pot
        ]);
    }

    private function __createRoom($settings = []) {
        $user = $this->__user ?: Auth::user();
        $created = !empty($settings);
        if(!empty($settings['name']) && Room::where('name', $settings['name'])->first()) {
            return response()->json(['message' => 'That Room name already exists: '.$settings['name']], 302);
        }
        if(isset($settings['pot']) && (!is_numeric($settings['pot']) || $settings['pot'] < 1 || $settings['pot'] > 10)) {
            return response()->json(['message' => 'Pot value should be from 1 to 10'], 302);
        }
        if(isset($settings['max_players']) && (!is_numeric($settings['max_players']) || $settings['max_players'] < 1 || $settings['max_players'] > 10)) {
            return response()->json(['message' => 'Max players should be from 2 to 8'], 302);
        }
        $settings += ['user_id' => $user->id, 'name' => !isset($settings['name']) ? ucwords(fake()->unique()->word).' '.Room::count() : $settings['name']];
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
        $this->__playedCard = $card = $this->playHand($user, $bet);
        $hand []= $card;
        $output = ['message' => 'You '.($bet > 0? 'win' : 'lose').' '.number_format($b = abs($bet)).' point'.($b == 1? '' : 's')] + ['cards' => $hand, 'points' => $user->getPoints(true)];
        return $this->checkEndRound($output, $status, true);
    }

    public function playHand($user, &$bet) {
        if(empty($room = $this->__room ?: Room::find($user->getRoomID()))) {
            dump($message = 'No Room selected: User ID '.$user->id);
            return false; // throw new Exception($message);
        }
        $status = $room->analyze();
        if(empty($hand = $room->getHand($user_id = $user->id))) {
            dump(compact('status', 'hand'));
            dump($message = 'User has no hand: User ID '.$user_id);
            return false; // throw new Exception($message);
        }
        $card = $room->dealCard();
        if(intval($card) < intval(min($hand)) || intval($card) > intval(max($hand))) {
            $bet *= -1; // lose
        }
        Action::add('play', $room->id, compact('user_id', 'bet', 'card'));
        if($status['pot'] == $bet) { // winning means clearing pot
            $this->__getPots($room, $status['playing']); // make playing pay
        }
        return $card;
    }

    public function passHand($user_id) {
        $this->__user = $user = User::find($user_id);
        $this->__room = $room = Room::find($user->getRoomID());
        $this->__status = $room->analyze();
        return $this->__passHand('timeout');
    }

    private function __passHand($action = 'pass') {
        Action::add($action, $this->__room->id, [ 'user_id' => $this->__user->id]);
        $output = ['message' => 'You passed'];
        return $this->checkEndRound($output, $this->__status, true);
    }

    public function checkEndRound($output, $status, $refresh = false) {
        if(count($status['players']) < 2) {
            return $this->__getRoomStatus(true);
        }
        if($status['dealer'] == 0) {
            // dump($status);
            // throw new Exception('Invalid dealer');
            return response()->json(['message' => 'An error occurred'], 500);
        }
        if($status['dealer'] != $status['current']) { // not end round
            // dump('Next player');
            return response()->json($output + ($refresh ? $this->__room->analyze(true) : $status) + $this->__getUserStatus($status), 200);
        }
        // dump('End Round');
        return $this->__cleanupRound($output, $status);
    }

    private function __getNewPlayers($status) {
        // dump($status);
        return array_diff(array_keys($status['players']), $status['playing']);
    }

    private function __cleanupRound($output, $status, $rotate = true) {
        // end of round
        // check if new players came in
        $room = $this->__room ?: Room::find($status['room_id']);
        $pots = $shuffle = false;
        $room_id = $room->id;
        if($new_players = $this->__getNewPlayers($status)) {
            $this->__getPots($room, $new_players);
            $pots = true;
            // dump('Added to pot');
        }
        // check if deck has enough cards for all players
        if(($deck = $status['deck']) < count($status['players']) * 3 + 1) {
            Action::add('shuffle', $status['room_id']);
            $shuffle = true;
            // dump('Shuffle');
        }
        // dump(compact('room_id', 'new_players', 'deck', 'pots', 'shuffle'));
        $rotate && Action::add('rotate', $status['room_id']);
        return $this->__startRound($room, !empty($output['message']) ? $output['message'] : null);
    }

    public function leaveRoom($user, $kick = false) {
        if(empty($room = $this->__room ?: Room::find($user->getRoomID()))) {
            return response()->json(['message' => 'User is not in a room'], 302);
        }
        $status = $room->analyze(true);
        Action::add($kick ? 'kick' : 'leave', $room->id, ['user_id' => $user_id = $user->id]);
        $is_turn = $status['current'] == $user_id;
        $is_dealer = $status['dealer'] == $user_id;
        $status = $room->analyze(true);
        if(($is_dealer && $is_turn) || (count($status['playing']) <= 1 && count($status['players']) > 1)) {
            $this->__cleanupRound(['message' => ''], $status); // , count($status['playing']) > 2);
        }
        return response()->json(['message' => 'You left the room'] + $this->__blank + $this->__getUserStatus($this->__blank), 200);
    }
}
