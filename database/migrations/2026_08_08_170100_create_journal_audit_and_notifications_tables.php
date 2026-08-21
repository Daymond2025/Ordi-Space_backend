<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->string('entite_concernee')->nullable();
            $table->text('details')->nullable();
            $table->ipAddress('adresse_ip')->nullable();
            $table->timestamp('date_heure')->useCurrent();
        });

        Schema::create('notifications_ordispace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type_notification');
            $table->text('contenu');
            $table->boolean('lu')->default(false);
            $table->timestamp('date_envoi')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_ordispace');
        Schema::dropIfExists('journal_audit');
    }
};
