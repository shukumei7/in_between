<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\Action;

class Room extends Model
{
    use HasFactory;

    private $__analyzed = false;
    private $__deck = 52;
    private $__dealt = [];
    private $__discards = [];
    private $__players = [];
    private $__hands = [];
    private $__pot = 0;
    private $__dealer = 0;
    private $__turn = 0;
    private $__previous_time = 0;

    public function analyze() {
        if($this->__analyzed) {
            return false;
        }
        $this->__analyzed = true;
        return $this->__analyzeStatus();
    }

    public function getStatus() {
        return [
            'uuid'      => $this->uuid,
            'name'      => $this->name,
            'deck'      => $this->__deck,
            'discards'  => $this->__discards,
            'pot'       => $this->__pot,
            'players'   => $this->__players,
            'dealer'    => $this->__players[$this->__dealer],
            'current'   => $this->__players[$this->__turn]
        ];
    }

    public function getHands($user_id) {
        return $this->__hands[$user_id];
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
        // $this->__resetStatus(); // unnecessary
        foreach($actions as $action) {
            $this->__analyzeAction($action);
        }
        return true;
    }

    private function __resetStatus() {
        $this->__resetDeck();
        $this->__players = [];
        $this->__pot = $this->__dealer = $this->__turn = 0;
    }

    private function __resetDeck() {
        $this->__deck = 52;
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
                return $this->__getPot($bet);
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
        $this->__players []= $user_id;
    }

    private function __removePlayer($user_id) { // TODO: consider shifting dealer and turn
        unset($this->__players[array_search($user_id, $this->__players)]);
    }

    private function __getPot($bet) {
        $this->__pot += $bet;
    }

    private function __shuffleDeck() {
        $this->__resetDeck();
        if(++$this->__dealer >= count($this->__players)) {
            $this->__dealer = 0;
        }
    }

    private function __dealCard($user_id, $card) {
        $this->__dealt []= $card;
        !isset($this->__hands[$user_id]) && $this->__hands[$user_id] = [];
        $this->__hands[$user_id] []= $card;
    }

    private function __nextPlayer() {
        if(++$this->__turn >= count($this->__players)) {
            $this->__turn = 0;
        }
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
