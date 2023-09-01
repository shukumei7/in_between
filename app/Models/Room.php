<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\User;
use App\Models\Action;

class Room extends Model
{
    use HasFactory;

    private $__status = [];
    private $__dealt = [];
    private $__discards = [];
    private $__players = [];
    private $__hands = [];
    private $__pots = [];
    private $__pot = 0;
    private $__dealer = 0;
    private $__turn = 0;
    private $__previous_time = 0;
    private $__history = [];

    public function analyze($refresh = false) {
        if($this->__status && !$refresh) {
            return $this->__status;
        }
        if($this->__analyzeStatus()) {
            return $this->getStatus();
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

    public function getStatus() {
        return $this->__status = [
            'uuid'      => $this->uuid,
            'name'      => $this->name,
            'deck'      => 52 - count($this->__dealt),
            'discards'  => $this->__discards,
            'pot'       => $this->__pot,
            'players'   => $this->__players,
            'playing'   => array_keys($this->__pots),
            'dealer'    => $this->__players[$this->__dealer],
            'current'   => $this->__players[$this->__turn],
            'history'   => $this->__history
        ] + [   // debug
            'hands' => $this->__hands,
            'dealt' => $this->__dealt
        ];
    }

    public function getHands($user_id) {
        return empty($this->__hands[$user_id]) ? [] : $this->__hands[$user_id];
    }

    public function dealCard() {
        do {
            $num = rand(1, 13);
            $suit = rand(1, 4);
            $card = $num.$suit;
        } while(in_array($card, $this->__dealt));
        $this->__dealt []= $card;
        return $card;
    }

    private function __analyzeStatus() {
        if(empty($this->id) || empty($actions = Action::where('room_id', $this->id)->get()->toArray())) {
            return false;
        }
        $this->__resetStatus(); // unnecessary
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
        switch($action) {
            case 'join':
                return $this->__history []= ['message' => $name.' joined the room', 'time' => $time];
            case 'leave':
                return $this->__history []= ['message' => $name.' left the room', 'time' => $time];
            case 'shuffle':
                return $this->__history []= ['message' => 'Deck is shuffled', 'time' => $time];
            case 'pot':
                return $this->__history []= ['message' => $name.' added '.number_format(abs($bet)).' to the pot', 'time' => $time];
            case 'deal':
                return $this->__history []= ['message' => $name.' got a card', 'time' => $time];
            case 'pass':
                return $this->__history []= ['message' => $name.' passed', 'time' => $time];
            case 'play':
                return $this->__history []= ['message' => $name.' '.($bet > 0? 'won' : 'lost').' '.number_format(abs($bet)), 'time' => $time];
        }
    }

    private function __resetStatus() {
        $this->__resetDeck();
        $this->__players = [];
        $this->__pot = $this->__dealer = $this->__turn = 0;
    }

    private function __resetDeck() {
        $this->__discards = $this->__dealt = $this->__hands = [];
    }

    private function __analyzeAction($action) {
        if($action['time'] < $this->__previous_time) {
            throw new Exception('Invalid action order');
        }
        $this->__previous_time = $action['time'];
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
                return $this->__nextPlayer();
            case 'play':
                return $this->__play($user_id, $bet, $card);
        }
        return false;
    }

    private function __addPlayer($user_id) { // TODO: improve logic
        if($this->__turn > 0) {
            $this->__players = array_splice($this->__players, $this->__turn - 1, 1, [$user_id]);
            return;
        }
        $this->__players []= $user_id;
    }

    private function __removePlayer($user_id) { // TODO: consider shifting dealer and turn
        unset($this->__players[$index = array_search($user_id, $this->__players)]);
        unset($this->__pots[$user_id]);
        unset($this->__hands[$user_id]);
        $this->__players = array_values($this->__players);
        $this->__checkDealer();
        $this->__checkTurn();
    }

    private function __getPot($user_id, $bet) {
        $this->__pot -= $bet;
        $this->__pots[$user_id] = $bet;
    }

    private function __checkDealer() {
        if($this->__dealer >= count($this->__players)) {
            $this->__dealer = 0;
        }
    }

    private function __checkTurn() {
        if($this->__turn >= count($this->__players)) {
            $this->__turn = 0;
        }
    }

    private function __shuffleDeck() {
        $this->__resetDeck();
        $this->__dealer++;
        $this->__checkDealer();
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
        $hands = $this->__hands[$user_id];
        $min = min($hands);
        $max = max($hands);
        if($card > $min && $card < $max) {
            $this->__pot -= $bet;
        } else {
            $this->__pot += $bet;
        }
        $this->__discards []= $card;
        $this->__discards []= $min;
        $this->__discards []= $max;
    }
}
