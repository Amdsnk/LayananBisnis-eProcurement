<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_previews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('user_id');
            $t->string('resource');
            $t->jsonb('rows');
            $t->string('hash', 64);
            $t->string('status')->default('preview');
            $t->timestampTz('expires_at');
            $t->timestamps();
        });
        Schema::create('exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('user_id');
            $t->string('format');
            $t->string('status')->default('pending');
            $t->jsonb('filters');
            $t->string('key')->nullable();
            $t->string('error')->nullable();
            $t->timestampTz('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Roll-forward only');
    }
};
