<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_guest(): void
    {
        $before = User::count();
        $response = $this->post('/api/accounts');
        $after = User::count();
        $response->assertStatus(201);
        $this->assertTrue($response['message'] == 'New user created!');
        $this->assertTrue($before + 1 == $after);
        $user = User::latest('id')->first();
        $this->assertTrue($user['id'] == $response['user_id']);
        $this->assertTrue($user['remember_token'] == $response['token']);
    }

    public function test_register_name(): void
    {
        $response = $this->post('/api/accounts', $data = ['name' => fake()->name()]);
        $response->assertStatus(201);
        $this->assertTrue($response['message'] == 'New user created!');
        $this->assertTrue(!empty($response['user_id']));
        $user = User::latest('id')->first();
        $this->assertTrue($user['id'] == $response['user_id']);
        $this->assertTrue($user['remember_token'] == $response['token']);
        $this->assertTrue($user['name'] == $data['name']);
    }

    public function test_name_conflict(): void
    {
        $user = User::factory()->create();
        $response = $this->post('/api/accounts', $data = ['name' => $user->name]);
        $response->assertStatus(200);
        $this->assertTrue($response['message'] == 'That name is taken');
    }

    public function test_quick_login(): void {
        $user = User::factory()->create();
        $response = $this->post('/api/accounts', $data = ['id' => $user->id]);
        $response->assertStatus(401);
        $this->assertTrue($response['message'] == 'Access token is required');
        $response = $this->post('/api/accounts', $data + ['token' => 'wrongtoken']);
        $response->assertStatus(401);
        $this->assertTrue($response['message'] == 'User not found or invalid token');
        $response = $this->post('/api/accounts', $data + ['token' => $user->remember_token]);
        $response->assertStatus(202);
        $this->assertTrue($response['message'] == 'You are logged in');
        $this->assertTrue($response['user_name'] == $user->name);
        $this->assertTrue(!empty($response['token']));
    }

    public function test_slow_login(): void {
        $user = User::factory()->create(['password' => $pass = 'password']);
        $response = $this->post('/api/accounts', $data = ['password' => 'pass']);
        $response->assertStatus(401);
        $this->assertTrue($response['message'] == 'An email is required');
        $response = $this->post('/api/accounts', $data + ['email' => 'wrongtoken']);
        $response->assertStatus(401);
        $this->assertTrue($response['message'] == 'User not found or invalid password');
        $response = $this->post('/api/accounts', ['email' => $user->email, 'password' => $pass]);
        $response->assertStatus(202);
        $this->assertTrue($response['message'] == 'You are logged in');
        $this->assertTrue($response['user_name'] == $user->name);
        $this->assertTrue(!empty($response['token']));
    }


    public function test_unauthorized_update(): void
    {
        $user = User::factory()->create();
        $response = $this->put('/api/accounts/'.$user->id);
        $response->assertStatus(302);
    }

    public function test_empty_update(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->put('/api/accounts/'.$user->id);
        $response->assertStatus(200);
        $this->assertTrue($response['message'] == 'Nothing to update');
    }

    public function test_update_add_duplicate_email(): void
    {
        User::factory()->create([
            'email' => $email = fake()->unique()->safeEmail(),
        ]);
        $user = User::factory()->create([
            'email'             => null,
            'email_verified_at' => null
        ]);
        $response = $this->actingAs($user)->put('/api/accounts/'.$user->id, ['email' => $email]);
        $response->assertStatus(200);
        $this->assertTrue($response['message'] == 'That email is already registered');
    }

    private function __test_password_checks($user, $data) {
        $response = $this->actingAs($user)->put($url = '/api/accounts/'.$user->id, $data);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == $message = 'You need a password to secure this account');
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'passwordewq']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == $message);
        $response = $this->actingAs($user)->put($url, $data + ['confirm_password' => 'passwordeqw']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == $message);
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'pass', 'confirm_password' => 'pass']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == 'Your password needs to be at least '.MIN_PASS_LENGTH.' characters long');
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'passwqwer', 'confirm_password' => 'passwqwer']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == $message = 'Your password needs to have a upper-case letter, a lower-case case letter, a number, and at least one of the following symbols (!, @, #, $, %, ^, &, *, (, ), -, _, ., ?)');
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'PASS5123', 'confirm_password' => 'PASS5123']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == $message);
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'PaSS5123', 'confirm_password' => 'PaSS5123']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == $message);
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'PaSS5!123', 'confirm_password' => 'PaSS5?123']);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == 'Your passwords do not match');
        $response = $this->actingAs($user)->put($url, $data + ['create_password' => 'PaSS5!123', 'confirm_password' => 'PaSS5!123']);
        $response->assertStatus(202);
        return $response;
    }

    public function test_add_email(): void
    {
        $user = User::factory()->create([
            'email'             => null,
            'email_verified_at' => null
        ]);
        $response = $this->__test_password_checks($user,  $data = ['email' => fake()->unique()->safeEmail()]);
        $this->assertTrue($response['message'] == 'You have secured your account');
        $user = User::select('id', 'email', 'email_verified_at')->where('id', $user->id)->first();
        $this->assertTrue($user['email'] == $data['email']);
        $this->assertTrue($user['email_verified_at'] == null);
    }

    public function test_update_email(): void
    {
        $mark = strtotime('-'.IDENTITY_LOCK_TIME);
        $user = User::factory()->create([
            'identity_updated_at' => now()
        ]);
        $response = $this->actingAs($user)->put($url = '/api/accounts/'.$user->id, $data = ['email' => fake()->unique()->safeEmail()]);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your email');
        $user->identity_updated_at = strtotime('-12 hours');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your email');
        $user->identity_updated_at = strtotime('-23 hours - 1 minute');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your email');
        $user->identity_updated_at = strtotime('-23 hours - 55 minutes');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        //var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your email');
        $user->identity_updated_at = strtotime('-23 hours - 59 minutes');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your email');
        $user->identity_updated_at = strtotime('-'.IDENTITY_LOCK_TIME);
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(202);
        $this->assertTrue($response['message'] == 'Your email is updated and you must wait at least '.IDENTITY_LOCK_TIME.' before changing it again');
        $user = User::select('id', 'email', 'email_verified_at')->where('id', $user->id)->first();
        $this->assertTrue($user['email'] == $data['email']);
        $this->assertTrue($user['email_verified_at'] == null);
    }

    public function test_update_name(): void
    {
        $mark = strtotime('-'.IDENTITY_LOCK_TIME);
        $user = User::factory()->create([
            'identity_updated_at' => now()
        ]);
        $response = $this->actingAs($user)->put($url = '/api/accounts/'.$user->id, $data = ['name' => fake()->unique()->name()]);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your name');
        $user->identity_updated_at = strtotime('-12 hours');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your name');
        $user->identity_updated_at = strtotime('-23 hours - 1 minute');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your name');
        $user->identity_updated_at = strtotime('-23 hours - 55 minutes');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your name');
        $user->identity_updated_at = strtotime('-23 hours - 59 minutes');
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(406);
        // var_dump($response['message']);
        $this->assertTrue($response['message'] == 'You need to wait '.get_time_remaining(strtotime($user->identity_updated_at) - $mark).' before you can change your name');
        $user->identity_updated_at = strtotime('-'.IDENTITY_LOCK_TIME);
        $user->save();
        $response = $this->actingAs($user)->put($url, $data);
        $response->assertStatus(202);
        $this->assertTrue($response['message'] == 'Your name is updated and you must wait at least '.IDENTITY_LOCK_TIME.' before changing it again');
        $user = User::select('id', 'name')->where('id', $user->id)->first();
        $this->assertTrue($user['name'] == $data['name']);
    }

    public function test_update_password(): void
    {
        $user = User::factory()->create(['password' => $pass = 'password']);
        $response = $this->actingAs($user)->put($url = '/api/accounts/'.$user->id, ['current_password' => 'wrong']);
        $response->assertStatus(401);
        $this->assertTrue($response['message'] == 'Incorrect current password');
        $response = $this->actingAs($user)->put($url, ['current_password' => $pass, 'create_password' => $pass]);
        $response->assertStatus(406);
        $this->assertTrue($response['message'] == 'Your new password cannot be the same as your current one');
        $response = $this->__test_password_checks($user,  $data = ['current_password' => $pass]);
        $response->assertStatus(202);
        $this->assertTrue($response['message'] == 'You have updated your password');
    }

    public function test_view_user() : void {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/api/accounts/'.$user->id);
        $response->assertOk();
    }
}
