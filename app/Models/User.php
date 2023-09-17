<?php
namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Models\Action;
use App\Models\Variable;

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

    public function actions(): HasMany
    {
        return  $this->hasMany(Action::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    private $__room_id = null;
    private $__points = null;
    private $__bot_schemes = [
        'risky'     => [.3,.5,.7],
        'balance'   => [.5,.7,.9],
        'safe'      => [.7,.8,.9],
    ];

    public function activateBot($scheme = null) {
        $schemes = array_keys($this->__bot_schemes);
        !in_array($scheme, $schemes) && $scheme = null;
        $this->type = 'bot';
        $this->remember_token = $scheme ?: $schemes[rand(0, count($schemes) - 1)];
        return $this->save();
    }

    public function getRoomID($refresh = false) {
        if($this->__room_id != null && !$refresh) {
            return $this->__room_id;
        }
        if(empty($this->id)) {
            return false;
        }
        if(empty($latest_leave = $this->actions()->whereIn('action', ['leave', 'kick'])->orderBy('time', 'desc')->first())) {
            return $this->__room_id = !empty($latest_join = $this->actions()->where('action', 'join')->first())? $latest_join->room_id : 0;
        }
        return $this->__room_id = !empty($latest_join = $this->actions()->where('action', 'join')->where('id', '>', $latest_leave->id)->first())? $latest_join->room_id : 0;
    }

    public function getPoints($refresh = false) {
        if($this->__points != null && !$refresh) {
            return $this->__points;
        }
        if(empty($this->id)) {
            return false;
        }
        return $this->__points = STARTING_MONEY + array_sum($this->actions()->pluck('bet')->toArray());
    }

    public function decideMove($status) {
        if(empty($hand = $status['hand'])) {
            return false;
        }
        if(!isset($this->__bot_schemes[$this->remember_token])) {
            $this->activateBot();
        }
        $min = min($hand);
        $max = max($hand);
        $win = $max - $min - (floor($max/10) - floor($min/10)) * 6; // get cards to draw inside
        if(empty($deck = $status['deck'])) {
            return [ 'action' => 'leave' ]; // leave dead room
        }
        $discards = $status['discards'];
        foreach($discards as $discard) {
            if($discard > $min && $discard < $max) {
                $win--; // 1 less card to draw
            }
        }
        $chance = $win / $deck;
        $scheme = $this->__bot_schemes[$this->remember_token];
        if($chance < $scheme[0]) {
            return ['action' => 'pass'];
        }
        if($chance < $scheme[1]) {
            return ['action' => 'play', 'bet' => min($status['pot'], 2)];
        }
        $points = !empty($status['points']) && $status['user_id'] == $this->id? $status['points'] : $this->getPoints();
        if($chance < $scheme[2]) {
            return ['action' => 'play', 'bet' => min($status['pot'], max($points / 2, $points - RESTRICT_BET, RESTRICT_BET))];
        }
        return ['action' => 'play', 'bet' => min($status['pot'] - ($status['pot'] > 1 ? 1 : 0), max($points, RESTRICT_BET))];
    }

}
