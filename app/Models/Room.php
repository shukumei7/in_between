<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\User;
use App\Models\Action;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'passcode',
        'max_players',
        'pot'
    ];

    private $__status = [];
    private $__dealt = [];
    private $__discards = [];
    private $__players = [];
    private $__previous =[];
    private $__hands = [];
    private $__pots = [];
    private $__pot = 0;
    private $__dealer = 0;
    private $__turn = 0;
    private $__previous_time = 0;
    private $__activities = [];

    public function analyze($refresh = false) {
        if($this->__status && !$refresh) {
            return $this->__status;
        }
        if($this->__analyzeStatus()) {
            return $this->getStatus($refresh);
        }
        return false;
    }

    public function isPlayerPlaying($user_id) {
        return !empty($this->__pots[$user_id]);
    }

    public function isFull() {
        return count($this->__players) >= $this->max_players;
    }

    public function isLocked() {
        return !empty($this->passcode);
    }

    public function getDealt() {
        return $this->__dealt;
    }

    private function __getPlaying() {
        return array_values(array_intersect($this->__players, array_keys($this->__pots)));
    }

    public function getStatus($refresh = false) {
        if($this->__status && !$refresh) {
            return $this->__status;
        }
        $active = count($this->__players) > 1;
        $this->__status = [
            'activities'   => $this->__activities,
            'room_id'   => $this->id,
            'name'      => $this->name,
            'deck'      => 52 - count($this->__dealt),
            'discards'  => $this->__discards,
            'hidden'    => count($this->__dealt) - count($this->__discards),
            'pot'       => $this->__pot,
            'players'   => $this->__players,
            'playing'   => $playing = $this->__getPlaying(),
            'dealer'    => $active && !empty($playing[$this->__dealer]) ? $playing[$this->__dealer] : 0,
            'current'   => $active && !empty($playing[$this->__turn]) ? $playing[$this->__turn] : 0,
        ];
        if(env('APP_ENV') == 'testing') {
            $this->__status += [   // debug
                'hands'     => $this->__hands,
                'dealt'     => $this->__dealt
            ];
        }
        return $this->__status;
    }

    public function getHand($user_id) {
        return empty($this->__hands[$user_id]) ? [] : $this->__hands[$user_id];
    }

    public function getRemainingHands() {
        return array_filter($this->__hands);
    }

    /*
    public function playHand($user_id) {
        $dealt = Action::select('card')->where('room_id', $room->id)->where('user_id', $user_id)->where('action', 'deal')->orderBy('time', 'desc')->get()->toArray();
        dd($dealt);
        $card = $this->dealCard();
        return $card > min($dealt) && $card < max($dealt);
    }
    */

    public function dealCard() {
        do {
            $num = rand(1, 13);
            $suit = rand(1, 4);
            $card = $num.$suit;
        } while(in_array($card, $this->__dealt));
        $this->__dealt []= $card = intval($card);
        return $card;
    }

    private function __analyzeStatus() {
        if(empty($this->id) || empty($actions = Action::where('room_id', $this->id)->orderBy('id', 'asc')->get()->toArray())) {
            return false;
        }
        $this->__resetStatus();
        $users = [];
        foreach($actions as $event) {
            $this->__analyzeAction($event);
            $this->__formatEvent($event);
        }
        return true;
    }

    private function __formatEvent($event) {
        extract($event);
        $name = $user_id ? (isset($users[$user_id]) ? $users[$user_id] : $users[$user_id] = User::find($user_id)->name) : '';
        $name .= ' ('.$user_id.')';
        switch($action) {
            case 'join':
                return $this->__activities []= ['message' => $name.' joined the room', 'time' => $time];
            case 'leave':
                return $this->__activities []= ['message' => $name.' left the room', 'time' => $time];
            case 'shuffle':
                return $this->__activities []= ['message' => 'Deck is shuffled', 'time' => $time];
            case 'pot':
                return $this->__activities []= ['message' => $name.' added '.number_format($b = abs($bet)).' point'.($b == 1 ? '' : 's').' to the pot', 'time' => $time];
            case 'deal':
                return $this->__activities []= ['message' => $name.' got a card', 'time' => $time];
            case 'pass':
                return $this->__activities []= ['message' => $name.' passed', 'time' => $time];
            case 'play':
                return $this->__activities []= ['message' => $name.' '.($bet > 0? 'won' : 'lost').' '.number_format($b = abs($bet)).' point'.($b == 1? '' : 's'), 'time' => $time];
        }
    }

    private function __resetStatus() {
        $this->__resetDeck();
        $this->__status = $this->__activities = $this->__pots = $this->__players = [];
        $this->__previous_time = $this->__pot = $this->__dealer = $this->__turn = 0;
    }

    private function __resetDeck() {
        $this->__discards = $this->__dealt = $this->__hands = [];
    }

    private function __analyzeAction($action) {
        if($action['id'] < $this->__previous_time) {
            dd([
                'action_time'   => $action['id'],
                'previous_time' => $this->__previous_time,
                'comparison'    => $action['id'] < $this->__previous_time
            ]);
        }
        $this->__previous_time = $action['id'];
        $user_id = $action['user_id'];
        $bet = $action['bet'];
        $card = $action['card'];
        switch($action['action']) {
            case 'join':
                return $this->__addPlayer($user_id);
            case 'leave':
                return $this->__removePlayer($user_id);
            case 'pot':
                return $this->__getPot($user_id, $bet);
            case 'deal':
                return $this->__dealCard($user_id, $card);
            case 'shuffle':
                return $this->__shuffleDeck();
            case 'pass':
                $this->__hands[$user_id] = [];
                return $this->__nextPlayer();
            case 'rotate':
                return $this->__nextDealer();
            case 'play':
                return $this->__play($user_id, $bet, $card);
        }
        return false;
    }

    private function __addPlayer($user_id) { // TODO: improve logic
        $this->__previous = [
            'players'   => $this->__players,
            'playing'   => $this->__getPlaying()
        ];
        if($this->__dealer < count($this->__getPlaying()) - 1) {
            array_splice($this->__players, $this->__dealer, 0, $user_id);
            return;
        }
        $this->__players []= $user_id;
    }

    private function __removePlayer($user_id) { // TODO: consider shifting dealer and turn
        $this->__previous = [
            'players'   => $this->__players,
            'playing'   => $this->__getPlaying()
        ];
        unset($this->__players[$player_index = array_search($user_id, $this->__players)]);
        $this->__players = array_values($this->__players);
        if(!isset($this->__pots[$user_id])) {
            return; // left before playing
        }
        $playing = $this->__getPlaying(); // get before clearing
        // $is_dealer = $playing[$this->__dealer] == $user_id;
        $hands = $this->__hands[$user_id]; // get before clearing
        unset($this->__pots[$user_id]); // clear player data
        unset($this->__hands[$user_id]); // clear player data
        if($this->__turn == $this->__dealer) {
            return; // should be solved by rotating
        }
        if($this->__turn >= count($playing)) {
            $this->__checkTurn();
        } else if(empty($hands)) { // turn is done
            $this->__turn--;    
            $this->__checkTurn();
        }
        if($player_index <= $this->__dealer) {
            $this->__dealer--;
            $this->__checkDealer();
        } else if($this->__dealer >= count($playing)) {
            $this->__checkDealer();
        }
    }

    private function __getPot($user_id, $bet) {
        $this->__pot -= $bet;
        $this->__pots[$user_id] = $bet;
    }

    private function __checkDealer() {
        if($this->__dealer < 0) {
            $this->__dealer = count($this->__getPlaying()) - 1;
        }
        if($this->__dealer >= count($this->__getPlaying())) {
            $this->__dealer = 0;
        }
    }

    private function __checkTurn() {
        if($this->__turn < 0) {
            $this->__turn = count($this->__getPlaying()) - 1;
        }
        if($this->__turn >= count($this->__getPlaying())) {
            $this->__turn = 0;
        }
    }

    private function __shuffleDeck() {
        $this->__resetDeck();
        $this->__resetTurn();
    }

    private function __nextDealer() {
        $this->__dealer++;
        $this->__checkDealer();
        $this->__resetTurn();
    }

    private function __resetTurn() {
        $this->__turn = $this->__dealer + 1;
        $this->__checkTurn();
    }

    private function __dealCard($user_id, $card) {
        $this->__dealt []= $card;
        !isset($this->__hands[$user_id]) && $this->__hands[$user_id] = [];
        $this->__hands[$user_id] []= $card;
        count($this->__hands[$user_id]) == 2 && sort($this->__hands[$user_id]);
    }

    private function __nextPlayer() {
        $this->__turn++;
        $this->__checkTurn();
    }

    private function __play($user_id, $bet, $card) {
        $this->__pot -= $bet;
        $this->__dealt []= $card;
        $this->__discards []= $card;
        $this->__discards []= min($this->__hands[$user_id]);
        $this->__discards []= max($this->__hands[$user_id]);
        $this->__hands[$user_id] = [];
        $this->__nextPlayer();
    }
}
