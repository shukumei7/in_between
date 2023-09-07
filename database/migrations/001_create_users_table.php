<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('identity_updated_at')->nullable();
            $table->timestamp('password_updated_at')->nullable();
            $table->timestamp('points_updated_at')->nullable();
            $table->string('access_token')->nullable();
            $table->enum('type', ['user', 'admin', 'disabled', 'bot'])->default('user')->indexed();
            $table->rememberToken();
            $table->timestamps();
            $table->index('remember_token');
            $table->index(['email', 'password']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
