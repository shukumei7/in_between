<?php
namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Models\Action;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'access_token',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'identity_updated_at' => 'datetime',
        'password_updated_at' => 'datetime',
        'password' => 'hashed',
    ];

    private $__room_id = null;
    private $__points = null;
    private $__bot_scheme = 'none';
    private $__bot_schemes = [
        'risky'     => [.3,.5,.7],
        'balance'   => [.5,.7,.9],
        'safe'      => [.7,.8,.9],
    ];

    public function activateBot() {
        $schemes = array_keys($this->__bot_schemes);
        $this->__bot_scheme = $schemes[rand(0, count($schemes) - 1)];
    }

    public function getBotScheme() {
        return $this->__bot_scheme;
    }

    public function getRoomID() {
        if($this->__room_id != null) {
            return $this->__room_id;
        }
        if(empty($this->id)) {
            return false;
        }
        if(empty($latest_leave = Action::where('user_id', $this->id)->where('action', 'leave')->orderBy('time', 'desc')->first())) {
            return $this->__room_id = !empty($latest_join = Action::where('user_id', $this->id)->where('action', 'join')->orderBy('time', 'desc')->first())? $latest_join->room_id : 0;
        }
        return $this->__room_id = !empty($latest_join = Action::where('user_id', $this->id)->where('action', 'join')->where('time', '>', $latest_leave->time)->orderBy('time', 'desc')->first())? $latest_join->room_id : 0;
    }

    public function getPoints($refresh = false) {
        if($this->__points != null && !$refresh) {
            return $this->__points;
        }
        if(empty($this->id)) {
            return false;
        }
        return $this->__points = array_sum(array_map(function($a) { return $a['bet']; }, Action::select('bet')->where('user_id', $this->id)->get()->toArray()));
    }

    public function decideMove($status) {
        if(empty($hand = $status['hand']) || !isset($this->__bot_schemes[$this->__bot_scheme])) {
            return false;
        }
        $min = min($hand);
        $max = max($hand);
        $win = $max - $min - (floor($max/10) - floor($min/10)) * 6; // get cards to draw inside
        $deck = $status['deck'];
        $discards = $status['discards'];
        foreach($discards as $discard) {
            if($discard > $min && $discard < $max) {
                $win--; // 1 less card to draw
            }
        }
        $chance = $win / $deck;
        $scheme = $this->__bot_schemes[$this->__bot_scheme];
        if($chance < $scheme[0]) {
            return ['action' => 'pass'];
        }
        if($chance < $scheme[1]) {
            return ['action' => 'play', 'bet' => min($status['pot'], RESTRICT_BET)];
        }
        $points = !empty($status['points']) && $status['user_id'] == $this->id? $status['points'] : $this->getPoints();
        if($chance < $scheme[2]) {
            return ['action' => 'play', 'bet' => min($status['pot'], max($points / 2, $points - RESTRICT_BET, RESTRICT_BET))];
        }
        return ['action' => 'play', 'bet' => min($status['pot'] - ($status['pot'] > 1 ? 1 : 0), max($points, RESTRICT_BET))];
    }

    public function isBot() {
        return !empty($this->__bot_scheme) && $this->__bot_scheme != 'none';
    }

}
