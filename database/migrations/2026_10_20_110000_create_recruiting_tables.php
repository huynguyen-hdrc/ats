<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuyển dụng: vòng tuyển (stages), ứng viên (candidates), lịch sử (candidate_events).
 * Giữ tên bảng/cột như Supabase để chuyển dữ liệu giữ nguyên id và đường dẫn file CV.
 * Các trigger của Postgres (ghi sự kiện, cập nhật stage_changed_at) nằm ở App\Observers\CandidateObserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('label', 64);
            $table->integer('sort_order')->default(0);
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('current_title')->nullable();
            $table->string('current_company')->nullable();
            $table->decimal('years_experience', 6, 2)->nullable();
            $table->string('source')->nullable();
            // Xoá vị trí không làm mất ứng viên: job_id → null (như app cũ).
            $table->foreignUuid('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('stage', 32)->default('new');
            $table->foreign('stage')->references('key')->on('stages')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('status', ['active', 'rejected', 'withdrawn'])->default('active');
            $table->json('skills')->nullable();
            $table->mediumText('cv_text')->nullable();    // tối đa 20 000 ký tự (≤ 80 KB utf8mb4), cắt trước khi lưu
            $table->string('cv_path', 500)->nullable();   // "{candidate_id}/{tên file}" trong kho cvs
            $table->string('cv_filename')->nullable();
            $table->timestamp('stage_changed_at')->useCurrent();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'stage_changed_at']);
            $table->index('email');
        });

        Schema::create('candidate_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->enum('type', ['created', 'stage_change', 'status_change', 'note']);
            $table->text('body')->nullable();            // ghi chú; với status_change là mã trạng thái mới
            $table->string('from_stage', 32)->nullable(); // không đặt khoá ngoại: lịch sử giữ nguyên dù vòng đổi
            $table->string('to_stage', 32)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['candidate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_events');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('stages');
    }
};
