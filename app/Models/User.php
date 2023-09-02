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

}
