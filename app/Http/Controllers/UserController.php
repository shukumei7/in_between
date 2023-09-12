<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserController extends Controller
{
    
    public function register(Request $request) {
        if(!empty($request->id)) {
            return $this->__quickLogin($request);
        }
        if(!empty($request->password)) {
            return $this->__slowLogin($request);
        }
        if(!empty($request->name)) {
            return $this->__registerNamedGuest($request->name);
        }
        return $this->__registerGuest(); 
    }

    private function __registerGuest() {
        while(!empty(User::where('name', $name = fake()->name())->first()));
        $user = new User;
        $user->name = $name;
        $user->remember_token = Str::random(TOKEN_LENGTH);
        $user->save();
        Auth::login($user, true);
        return $this->__returnNewUser($user->id, $user->remember_token, $user->createToken('IB')->plainTextToken);
    }

    private function __registerNamedGuest($name) {
        if(!empty(User::where('name', $name)->first())) {
            return response()->json(['message' => 'That name is taken'], 200);
        }
        $user = new User;
        $user->name = $name;
        $user->remember_token = Str::random(TOKEN_LENGTH);
        $user->save();
        Auth::login($user);
        return $this->__returnNewUser($user->id, $user->remember_token, $user->createToken('IB')->plainTextToken);
    }

    private function __returnNewUser($user_id, $token = null, $access = null) {
        return response()->json(['message' => 'New user created!', 'user_id' => $user_id] + ($token ? ['token' => $token] : []) + ($access ? ['access' => $access] : []), 201);
    }

    private $__active_user_types = ['user', 'admin'];

    private function __slowLogin($request) {
        if(empty($request->email)) {
            return response()->json(['message' => 'An email is required'], 401);
        }
        if(!Auth::attempt(['email' => $request->email, 'password' => $request->password, 'type' => $this->__active_user_types], !empty($request->remember))) {
            return response()->json(['message' => 'User not found or invalid password'], 401);
        }
        return $this->__returnLogin();
    }

    private function __quickLogin($request) {
        if(empty($request->token)) {
            return response()->json(['message' => 'Access token is required'], 401);
        }
        if(empty($user = User::where('id', $request->id)->where('remember_token', $request->token)->whereIn('type', $this->__active_user_types)->first())) {
            return response()->json(['message' => 'User not found or invalid token'], 401);
        }
        Auth::login($user, true);
        return $this->__returnLogin();
    }

    private function __returnLogin() {
        $user = Auth::user();
        $user->points_updated_at = null;
        $user->save();
        return response()->json([
            'message'   => 'You are logged in',
            'token'     => $user->createToken(env('APP_NAME', 'In Between'))->plainTextToken,
            'name'      => $user->name,
            'user_id'   => $user->id,
            'room_id'   => $user->getRoomID(),
            'points'    => $user->getPoints()
        ], 202);
    }

    public function update(Request $request, $id) {
        $request->id = $id;
        if(empty($user = User::select('id', 'name', 'email', 'identity_updated_at')->where('id', $request->id)->whereIn('type', ['user', 'admin'])->first())) {
            return response()->json(['message' => 'User not found or invalid token'], 401);
        }
        if(!empty($request->email)) {
            return $this->__updateUserEmail($request, $user);
        }
        if(!empty($request->name)) {
            return $this->__updateUserName($request, $user);
        }
        if(!empty($request->current_password)) {
            return $this->__updateUserPassword($request);
        }
        return response()->json(['message' => 'Nothing to update'], 200);
    }

    private function __updateUserName($request, $user) {
        if(!empty(User::where('name', $request->name)->get()->value('id'))) {
            return response()->json(['message' => 'That name is taken'], 200);
        }
        if(true !== $return = $this->__checkIdentityUpdate($user, 'name')) {
            return $return;
        }
        $user->name = $request->name;
        $user->identity_updated_at = now();
        $user->save();
        return response()->json(['message' => 'Your name is updated and you must wait at least '.IDENTITY_LOCK_TIME.' before changing it again'], 202);
    }

    private function __checkIdentityUpdate($user, $field) {
        if($user->identity_updated_at && ($t = strtotime($user->identity_updated_at) - strtotime('-'.IDENTITY_LOCK_TIME)) > 0) {
            return response()->json(['message' => 'You need to wait '.get_time_remaining($t).' before you can change your '.$field], 406);
        }
        return true;
    }

    private function __updateUserEmail($request, $user) {
        if(!empty(User::where('email', $request->email)->get()->value('id'))) {
            return response()->json(['message' => 'That email is already registered'], 200);
        }
        if(empty($user->email)) {
            return $this->__addUserEmail($request, $user);
        }
        if(true !== $return = $this->__checkIdentityUpdate($user, 'email')) {
            return $return;
        }
        $user->email = $request->email;
        $user->email_verified_at = null;
        $user->identity_updated_at = now();
        $user->save();
        return response()->json(['message' => 'Your email is updated and you must wait at least '.IDENTITY_LOCK_TIME.' before changing it again'], 202);
    }

    private function __checkNewPassword($request) {
        if(empty($request->create_password) || empty($request->confirm_password)) {
            return response()->json(['message' => 'You need a password to secure this account'], 406);    
        }
        if(strlen($request->create_password) < MIN_PASS_LENGTH) {
            return response()->json(['message' => 'Your password needs to be at least '.MIN_PASS_LENGTH.' characters long'], 406);
        }
        if(!preg_match('/[A-Z]/', $request->create_password) || !preg_match('/[a-z]/', $request->create_password) || !preg_match('/[0-9]/', $request->create_password) || !preg_match('/[\!\@\#\$\%\^\&\*\(\)\.\?\_\-]/', $request->create_password)) {
            return response()->json(['message' => 'Your password needs to have a upper-case letter, a lower-case case letter, a number, and at least one of the following symbols (!, @, #, $, %, ^, &, *, (, ), -, _, ., ?)'], 406);
        }
        if($request->create_password != $request->confirm_password) {
            return response()->json(['message' => 'Your passwords do not match'], 406);
        }
        return true;
    }

    private function __addUserEmail($request, $user) {
        if(true !== $return = $this->__checkNewPassword($request)) {
            return $return;
        }
        $user->email = $request->email;
        $user->password = $request->create_password;
        $user->email_verified_at = null;
        $user->password_updated_at = $user->identity_updated_at = now();
        $user->save();
        return response()->json(['message' => 'You have secured your account'], 202);
    }

    private function __updateUserPassword(Request $request) {
        if(empty($user = User::find($request->id)) || !Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Incorrect current password'], 401);
        }
        if($request->current_password == $request->create_password) {
            return response()->json(['message' => 'Your new password cannot be the same as your current one'], 406);
        }
        if(true !== $return = $this->__checkNewPassword($request)) {
            return $return;
        }
        $user->password = $request->create_password;
        $user->password_updated_at = now();
        $user->save();
        return response()->json(['message' => 'You have updated your password'], 202);
    }

    public function view($id) {
        $user = User::find($id);
        return response()->json([
            'message'           => 'Viewing User details',
            'name'              => $user->name,
            'email'             => $user->email,
            'joined'            => $user->created_at,
            'verified'          => !empty($user->email_verified_at) ? 'yes' : 'no',
            'points'            => $user->getPoints(),
            'points_updated_at' => $user->points_updated_at,
            'current_room_id'   => $user->getRoomID()
        ], 200);
    }
}
