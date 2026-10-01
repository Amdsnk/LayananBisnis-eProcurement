<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('locale')->default('id');
            $t->jsonb('settings')->default('{}');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
            $t->text('mfa_secret')->nullable();
            $t->text('mfa_pending')->nullable();
            $t->boolean('mfa_enabled')->default(false);
            $t->jsonb('recovery_codes')->nullable();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('role');
            $t->unsignedBigInteger('vendor_id')->nullable();
            $t->jsonb('scope')->default('{}');
            $t->unique(['tenant_id', 'user_id']);
        });
        foreach (['legal_entities', 'departments', 'cost_centers', 'warehouses', 'categories', 'items', 'tax_codes', 'currencies', 'payment_terms', 'procurement_plans'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->string('code');
                $t->string('name');
                $t->boolean('active')->default(true);
                $t->jsonb('data')->default('{}');
                $t->timestamps();
                $t->unique(['tenant_id', 'code']);
                $t->unique(['tenant_id', 'id']);
            });
        }
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code');
            $t->string('name');
            $t->string('email');
            $t->string('status')->default('draft');
            $t->string('tax_id')->nullable();
            $t->jsonb('data')->default('{}');
            $t->unsignedBigInteger('created_by');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('vendor_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('vendor_id');
            $t->unsignedBigInteger('created_by');
            $t->jsonb('data');
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('legal_entity_id');
            $t->unsignedBigInteger('cost_center_id');
            $t->string('name');
            $t->string('currency', 3)->default('IDR');
            $t->integer('year');
            $t->decimal('allocated', 20, 4);
            $t->decimal('reserved', 20, 4)->default(0);
            $t->decimal('committed', 20, 4)->default(0);
            $t->decimal('actual', 20, 4)->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'legal_entity_id'])->references(['tenant_id', 'id'])->on('legal_entities');
            $t->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers');
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('kind');
            $t->string('number');
            $t->string('title');
            $t->string('status')->default('draft');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('legal_entity_id');
            $t->unsignedBigInteger('cost_center_id');
            $t->unsignedBigInteger('budget_id')->nullable();
            $t->unsignedBigInteger('vendor_id')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('currency', 3)->default('IDR');
            $t->decimal('exchange_rate', 20, 8)->default(1);
            $t->decimal('total', 20, 4)->default(0);
            $t->decimal('budget_value', 20, 4)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->date('needed_at')->nullable();
            $t->timestampTz('deadline')->nullable();
            $t->timestampTz('opened_at')->nullable();
            $t->jsonb('data')->default('{}');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'kind', 'status']);
            foreach (['legal_entity_id' => 'legal_entities', 'cost_center_id' => 'cost_centers', 'budget_id' => 'budgets', 'vendor_id' => 'vendors', 'source_id' => 'documents'] as $c => $table) {
                $t->foreign(['tenant_id', $c])->references(['tenant_id', 'id'])->on($table);
            }
        });
        Schema::create('document_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('source_line_id')->nullable();
            $t->string('description');
            $t->string('uom')->default('unit');
            $t->decimal('quantity', 20, 4);
            $t->decimal('unit_price', 20, 4);
            $t->decimal('discount_percent', 8, 4)->default(0);
            $t->decimal('tax_percent', 8, 4)->default(0);
            $t->decimal('net', 20, 4);
            $t->decimal('tax', 20, 4);
            $t->decimal('total', 20, 4);
            $t->jsonb('data')->default('{}');
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->foreign(['tenant_id', 'source_line_id'])->references(['tenant_id', 'id'])->on('document_lines');
        });
        Schema::create('workflow_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->string('kind');
            $t->unsignedInteger('version');
            $t->boolean('active')->default(true);
            $t->jsonb('steps');
            $t->jsonb('conditions')->default('{}');
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'version']);
        });
        Schema::create('approval_steps', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedInteger('round');
            $t->unsignedInteger('position');
            $t->unsignedBigInteger('approver_id');
            $t->string('status')->default('pending');
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('acted_by')->nullable();
            $t->timestampTz('acted_at')->nullable();
            $t->jsonb('snapshot');
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->unique(['document_id', 'round', 'position', 'approver_id']);
        });
        Schema::create('delegations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('from_user_id')->constrained('users');
            $t->foreignId('to_user_id')->constrained('users');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('reason');
            $t->timestamps();
        });
        Schema::create('document_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->integer('version');
            $t->jsonb('snapshot');
            $t->unsignedBigInteger('actor_id');
            $t->string('reason');
            $t->timestamps();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
        });
        Schema::create('budget_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('budget_id');
            $t->unsignedBigInteger('document_id');
            $t->string('action');
            $t->decimal('reserved_delta', 20, 4);
            $t->decimal('committed_delta', 20, 4);
            $t->decimal('actual_delta', 20, 4);
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['tenant_id', 'budget_id'])->references(['tenant_id', 'id'])->on('budgets');
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
        });
        Schema::create('tender_invitations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('vendor_id');
            $t->unique(['document_id', 'vendor_id']);
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        Schema::create('bids', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('vendor_id');
            $t->integer('revision');
            $t->string('status')->default('submitted');
            $t->text('technical_cipher');
            $t->text('commercial_cipher');
            $t->string('hash', 64);
            $t->timestampTz('submitted_at');
            $t->unique(['document_id', 'vendor_id', 'revision']);
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        Schema::create('opening_votes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('user_id');
            $t->string('envelope');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['document_id', 'user_id', 'envelope']);
        });
        Schema::create('evaluations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('vendor_id');
            $t->unsignedBigInteger('evaluator_id');
            $t->jsonb('scores');
            $t->decimal('score', 8, 4);
            $t->text('comment');
            $t->boolean('conflict_declared')->default(false);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['document_id', 'vendor_id', 'evaluator_id']);
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors');
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('title');
            $t->jsonb('data')->default('{}');
            $t->timestampTz('read_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('type');
            $t->jsonb('payload');
            $t->string('status')->default('pending');
            $t->integer('attempts')->default(0);
            $t->string('last_error')->nullable();
            $t->timestampTz('available_at')->useCurrent();
            $t->timestamps();
        });
        Schema::create('integration_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->string('protocol');
            $t->string('endpoint')->nullable();
            $t->text('secret')->nullable();
            $t->boolean('enabled')->default(false);
            $t->jsonb('mapping')->default('{}');
            $t->timestamps();
        });
        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('external_id');
            $t->integer('sequence');
            $t->jsonb('payload');
            $t->timestamps();
            $t->unique(['tenant_id', 'external_id']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action');
            $t->string('object_type');
            $t->string('object_id');
            $t->jsonb('data');
            $t->string('correlation_id');
            $t->string('previous_hash', 64)->nullable();
            $t->string('hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'id']);
        });
        Schema::create('idempotency_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('actor_id');
            $t->string('key');
            $t->string('request_hash', 64);
            $t->jsonb('response')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'actor_id', 'key']);
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('uploaded_by');
            $t->string('name');
            $t->string('key');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('hash', 64);
            $t->string('status')->default('quarantined');
            $t->timestamps();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'audit events are append only'; END $$; CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();");
    }

    public function down(): void
    {
        throw new RuntimeException('Destructive rollback disabled; use reviewed roll-forward and restore procedure.');
    }
};
