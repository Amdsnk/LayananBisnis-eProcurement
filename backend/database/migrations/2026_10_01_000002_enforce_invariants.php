<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX invoice_number_unique ON documents (tenant_id,legal_entity_id,vendor_id,(data->>'normalized_number')) WHERE kind='invoice'");
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budget_nonnegative CHECK (allocated>=0 AND reserved>=0 AND committed>=0 AND actual>=0 AND reserved+committed+actual<=allocated)');
        DB::statement('ALTER TABLE document_lines ADD CONSTRAINT positive_quantity CHECK (quantity>0 AND unit_price>=0 AND discount_percent BETWEEN 0 AND 100 AND tax_percent BETWEEN 0 AND 100)');
        Schema::table('evaluations', function (Blueprint $t) {
            $t->string('phase')->default('technical');
            $t->dropUnique('evaluations_document_id_vendor_id_evaluator_id_unique');
            $t->unique(['document_id', 'vendor_id', 'evaluator_id', 'phase']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('mfa_last_counter')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Use roll-forward.');
    }
};
