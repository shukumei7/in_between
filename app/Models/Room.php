<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

use App\Models\User;
use App\Models\Action;
use App\Models\Snapshot;

class Room extends Model
{
    use HasFactory;

    public static $debug = false;
    public static $snapshot_threshold = SNAPSHOT_THRESHOLD;

    protected $fillable = [
        'user_id',
        'name',
        'passcode',
        'max_players',
        'pot'
    ];

    protected $casts = [
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime'
    ];

    public function actions(): HasMany 
    {
        return $this->hasMany(Action::class);
    }

    public function snapshot(): HasOne 
    {
        return $this->hasOne(Snapshot::class);
    }

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
    private $__scores = [];
    private $__snapshot = null;

    public function analyze($refresh = false, $rebuild = false) {
        if($this->__status && !$refresh && !$rebuild) {
            return $this->__status;
        }
        if($this->__analyzeStatus($rebuild)) {
            return $this->getStatus($refresh || $rebuild);
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
        return count($this->__players) > 1 ? (array_values(array_intersect($this->__players, array_keys($this->__pots)))) : [];
    }

    public function getStatus($refresh = false) {
        if($this->__status && !$refresh) {
           return $this->__status;
        }
        //        dump('Refresh analysis for Room '.$this->id);
        $active = count($this->__players) > 1;
        $playing = $this->__getPlaying();
        $users = User::whereIn('id', $playing)->get();
        $names = User::whereIn('id', Action::select(DB::raw('DISTINCT user_id as user_id'))->whereNotNull('user_id')->pluck('user_id')->toArray())->pluck('name', 'id')->toArray();
        $hands = array_map(function($a) { return count($a); }, $this->__hands);
        $activities = array_map(function($event) use ($names) { if($event['user_id']) $event['name'] = $names[$event['user_id']]; return $event; }, array_merge($this->__snapshot ? [Action::find($this->__snapshot->action_id)->toArray()] : [], $this->__activities));
        $this->__status = [
            'activities'    => $activities,
            'room_id'       => $this->id,
            'room_name'     => $this->name,
            'deck'          => 52 - count($this->__dealt),
            'discards'      => $this->__discards,
            'hidden'        => count($this->__dealt) - count($this->__discards) - array_sum($hands),
            'pot'           => $this->__pot,
            'players'       => array_replace(array_flip($this->__players), User::whereIn('id', $this->__players)->pluck('name', 'id')->toArray()),
            'playing'       => $playing,
            'dealer'        => $active && !empty($playing[$this->__dealer]) ? $playing[$this->__dealer] : 0,
            'current'       => $active && !empty($playing[$this->__turn]) ? $playing[$this->__turn] : 0,
            'hands'         => $hands,
            'scores'        => $this->__scores
        ];
        if(env('APP_ENV') == 'testing') {
            $this->__status['hands'] = $this->__hands;
            $this->__status['dealt'] = $this->__dealt;
            $this->__status['previous'] = $this->__previous;
            $this->__status['dealer_index'] = $this->__dealer;
            $this->__status['current_index'] = $this->__turn;
            $this->__status['pots'] = array_keys($this->__pots);
            $this->__status['snapshot'] = $this->__snapshot ? $this->__snapshot->toArray() : [];
            return $this->__status;
        }
        return $this->__status;
    }

    public function getHand($user_id) {
        $hand = empty($this->__hands[$user_id]) ? [] : $this->__hands[$user_id];
        sort($hand);
        return $hand;
    }

    public function getRemainingHands() {
        return array_filter($this->__hands);
    }

    public function dealCard() {
        do {
            $num = rand(2, 14);
            $suit = rand(1, 4);
            $card = $num.$suit;
        } while(in_array($card, $this->__dealt));
        $this->__dealt []= $card = intval($card);
        return $card;
    }

    private function __saveSnapshot() {
        $condition = ['room_id' => $this->id];
        $snapshot = Snapshot::firstOrNew($condition, $condition);
        if(empty($this->__activities)) {
            $this->__analyzeStatus();
        }
        $last = end($this->__activities);
        $snapshot->action_id = $last['id'];
        $count = count($ints = ['pot', 'dealer', 'turn']);
        for($x = 0; $x < $count; $x++) {
            $int = $ints[$x];
            $__int = '__'.$int;
            $snapshot->$int = $this->$__int;
        }
        $count = count($arrays = ['dealt', 'discards', 'players', 'hands', 'pots', 'scores', 'previous']);
        for($x = 0; $x < $count; $x++) {
            $array = $arrays[$x];
            $__array = '__'.$array;
            $snapshot->$array = json_encode($this->$__array);
        }
        env('APP_ENV') == 'testing' && dump('Save Snapshot for Room '.$this->id);
        $snapshot->save();
        return $this->__snapshot = $snapshot;
    }

    private function __loadSnapshot() {
        if(empty($snapshot = $this->snapshot()->first())) {
            return false;
        }
        $this->__snapshot = $snapshot;
        $count = count($ints = ['pot', 'dealer', 'turn']);
        for($x = 0; $x < $count; $x++) {
            $int = $ints[$x];
            $__int = '__'.$int;
            $this->$__int = $snapshot->$int;
        }
        $count = count($arrays = ['dealt', 'discards', 'players', 'hands', 'pots', 'scores', 'previous']);
        for($x = 0; $x < $count; $x++) {
            $array = $arrays[$x];
            $__array = '__'.$array;
            $this->$__array = json_decode($snapshot->$array, true);
        }
        return true;
    }

    private function __getActions($fresh = false) {
        if($fresh || !$this->__loadSnapshot()) {
            if(self::$debug) dump('Get all actions for Room '.$this->id);
            return $this->actions()->orderBy('id', 'asc')->get()->toArray();
        }
        $snapshot = $this->__snapshot;
        if(self::$debug) dump('Get recent actions for Room '.$this->id);
        return $this->actions()->where('id', '>', $snapshot->action_id)->orderBy('id', 'asc')->get()->toArray();
    }

    private function __analyzeStatus($rebuild = false) {
        $start = microtime(true);
        $this->__resetStatus();
        if(empty($this->id) || empty($actions = $this->__getActions($rebuild))) {
            // dump('No actions found for Room '.$this->id);
            return !empty($this->__snapshot);
        }
        foreach($actions as $event) {
            $this->__analyzeAction($event);
            $this->__formatEvent($event);
        }
        $loadTime = microtime(true) - $start;
        // if(env('APP_ENV') == 'testing') dump('Load Time: '.number_format($loadTime, 3));
        if($loadTime > self::$snapshot_threshold) {
            $this->__saveSnapshot(); // take a snapshot if load time goes beyond threshold
        }
        return true;
    }
/*
    private function __resetRoom() {
        // error, current player has no hands, reset game
        $new_actions= [];
        if($new_players = array_diff($this->__players, $this->__getPlaying())) {
            foreach($new_players as $user_id) { // add pots
                $new_actions []= Action::add('pot', $this->id, ['user_id' => $user_id, 'bet' => $pot = -1 * $this->pot]);
                $this->__getPot($user_id, $pot);
            }
        }
        $new_actions []= Action::add('shuffle', $this->id);
        $new_actions []= Action::add('rotate', $this->id);
        $playing = $this->__getPlaying();
        $this->__resetDeck();
        $this->__shuffleDeck();
        $this->__nextDealer();
        for($x = 0 ; $x < 2; $x++) {
            foreach($playing as $user_id) {
                $new_actions []= Action::add('deal', $this->id, ['user_id' => $user_id, 'card' => $card = $this->dealCard()]);
                $this->__dealCard($user_id, $card);
            }
        }
        return true;
    }
*/
    private function __formatEvent($event) {
        return $this->__activities []= array_intersect_key($event, [
            'id'           => null,
            'action'       => null,
            'user_id'      => null,
            'bet'          => null,
            'time'         => null
        ]); // return events un-formatted, format front end
    }

    private function __resetStatus() {
        $this->__resetDeck();
        $this->__status = $this->__activities = $this->__pots = $this->__players = [];
        $this->__previous_time = $this->__pot = $this->__dealer = $this->__turn = 0;
    }

    private function __resetDeck() {
        $this->__discards = $this->__dealt = [];
        $this->__hands = array_fill_keys($this->__players, []);
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
            case 'kick':
                return $this->__removePlayer($user_id);
            case 'pot':
                return $this->__getPot($user_id, $bet);
            case 'deal':
                return $this->__dealCard($user_id, $card);
            case 'shuffle':
                return $this->__shuffleDeck();
            case 'pass':
            case 'timeout':
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
        $this->__scores[$user_id] = 0;
        $this->__hands[$user_id] = [];
        if(count($this->__players) > 1 && $this->__dealer > 0 && $this->__dealer < count($playing = $this->__getPlaying()) - 1) {
            array_splice($this->__players, $this->__dealer, 0, $user_id);
            return;
        }
        $this->__players []= $user_id;
        $this->__players = array_unique($this->__players);
    }

    private function __removePlayer($user_id) { // TODO: consider shifting dealer and turn
        
        if(false === $global_index = array_search($user_id, $players = $this->__players)) {
            // $trace = trace(3);
            // dump(compact('user_id', 'global_index', 'players', 'trace'));
            return; // invalid leaving
        }
        $this->__previous = [
            'players'       => $this->__players,
            'playing'       => $playing = $this->__getPlaying(),
            'hands'         => $this->__hands,
            'dealer_index'  => $this->__dealer,
            'turn_index'    => $this->__turn,
            'leaver_index'  => $player_index = array_search($user_id, $playing),
            'change_turn'   => 'none',
            'change_dealer' => 'none'
        ];
        unset($this->__scores[$user_id]);
        unset($this->__players[$global_index]);
        $this->__players = array_values($this->__players);
        if(!isset($this->__pots[$user_id])) {
            return; // left before playing
        }
        if(!isset($this->__hands[$user_id])) {
            /*
            dump($this->getStatus()); // hwuat?
            dump(trace(6));
            */
            dump('Invalid room status for leaving: '.$user_id);
            return;
        }
        unset($this->__pots[$user_id]); // clear player data
        unset($this->__hands[$user_id]); // clear player data
        if(count($playing) <=2) {
            $this->__current = $this->__dealer = 0;
            return;
        }
        $current_turn = $playing[$this->__turn];
        if($player_index < $this->__turn) { // turn is done
            $this->__previous['change_turn'] = '-1';
            $this->__turn--;    
            $this->__checkTurn();
        } else if($this->__turn >= count($playing) - 1 || $player_index == $this->__turn) {
            $this->__checkTurn();
        }
        if($player_index < $this->__dealer || ($player_index == $this->__dealer && !empty($this->__hands[$current_turn]))) {
            $this->__previous['change_dealer'] = '-1';
            $this->__dealer--;
            $this->__checkDealer();
        } else if($this->__dealer >= count($playing) - 1) {
            $this->__checkDealer();
        }
    }

    private function __getPot($user_id, $bet) {
        $this->__pot -= $bet;
        $this->__pots[$user_id] = $bet;
        if(!isset($this->__scores[$user_id])) {
            $this->__scores[$user_id] = $bet;
            return; // fix anyway
            /*
            $activities = $this->__activities;
            $players = $this->__players;
            $scores = $this->__scores;
            $trace = array_map(function($a) { return $a; }, array_slice(debug_backtrace(), 0, 2));
            dd(compact('activities', 'players', 'scores', 'user_id', 'trace'));
            */
        }
        $this->__scores[$user_id] += $bet;
    }

    private function __checkDealer() {
        $playing = $this->__getPlaying();
        if($this->__dealer < 0) {
            $this->__dealer = max(0, count($playing) - 1);
        }
        if($this->__dealer >= count($playing)) {
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
        $this->__hands[$user_id] []= $card;
        // reset turn to enforce start of round
        $this->__turn = $this->__dealer + 1;
        $this->__checkTurn();
    }

    private function __nextPlayer() {
        $this->__turn++;
        $this->__checkTurn();
    }

    private function __getCurrentHand($user_id) {
        if(!isset($this->__hands[$user_id])) {
            return [];
        }
        return array_slice($this->__hands[$user_id], -2);
    }

    private function __play($user_id, $bet, $card) {
        $this->__pot -= $bet;
        $this->__scores[$user_id] += $bet;
        $this->__dealt []= $card;
        $this->__discards []= $card;
        if(empty($hand = $this->__getCurrentHand($user_id))) {
            // error and shouldn't happen
            $this->__nextPlayer();
            return;
        }
        $this->__discards []= $hand[0];
        $this->__discards []= $hand[1];
        $this->__hands[$user_id] = [];
        $this->__nextPlayer();
    }
}
