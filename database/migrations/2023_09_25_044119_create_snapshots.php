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
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained();
            $table->foreignId('action_id')->constrained();
            $table->integer('pot');  
            $table->integer('dealer');
            $table->integer('turn');
            $table->string('dealt');
            $table->string('discards');
            $table->string('players');
            $table->string('previous');
            $table->string('hands');
            $table->string('pots');
            $table->string('scores');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
