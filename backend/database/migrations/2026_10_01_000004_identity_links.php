<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('issuer');
            $t->string('subject');
            $t->timestamps();
            $t->unique(['issuer', 'subject']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Roll-forward only');
    }
};
