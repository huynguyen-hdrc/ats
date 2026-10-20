<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vai trò: 4 vai trò gốc (is_system) + vai trò tuỳ chỉnh kế thừa một vai trò gốc qua `base`.
        Schema::create('roles', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('label');
            $table->string('base', 16); // admin | hr | manager | staff
            $table->unsignedInteger('sort')->default(100);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_perms', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 64);
            $table->string('module', 32);
            $table->string('action', 16);
            $table->boolean('allowed')->default(false);
            $table->timestamps();
            $table->unique(['role_key', 'module', 'action']);
            $table->foreign('role_key')->references('key')->on('roles')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable(); // null khi mới được mời
            $table->string('role_key', 64)->default('staff');
            $table->string('status', 16)->default('invited'); // invited | active | locked
            $table->uuid('employee_id')->nullable()->index();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->foreign('role_key')->references('key')->on('roles')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->nullable()->index();   // người thực hiện (null: khách, vd đăng nhập sai)
            $table->string('action', 64)->index();          // vd auth.login, user.lock, candidates.delete
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // Hàng đợi của Laravel: đặt tên queue_jobs để nhường tên `jobs` cho vị trí tuyển dụng.
        Schema::create('queue_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'job_batches', 'queue_jobs', 'audit_logs', 'sessions',
            'password_reset_tokens', 'users', 'role_perms', 'roles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
