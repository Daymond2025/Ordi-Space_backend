<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_assistant_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->cascadeOnDelete();
            $table->enum('role', ['client', 'assistant']);
            $table->text('contenu');
            $table->timestamp('date_envoi')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_assistant_ia');
    }
};
