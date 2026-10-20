<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vị trí tuyển dụng & JD — giữ tên bảng `jobs` như Supabase (hàng đợi Laravel dùng `queue_jobs`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('department')->nullable();
            $table->unsignedInteger('headcount')->nullable()->default(1);
            $table->string('location')->nullable();
            $table->string('employment_type', 32)->nullable(); // Full-time | Part-time | Thực tập | Thời vụ
            $table->string('hiring_manager')->nullable();      // tên ghi tay, không phải khoá ngoại
            $table->string('salary_range')->nullable();        // ghi tay, vd "15-20tr"
            $table->date('target_date')->nullable();
            $table->boolean('is_open')->default(true);
            $table->text('jd_summary')->nullable();
            $table->text('jd_responsibilities')->nullable();   // mỗi dòng một ý
            $table->text('jd_requirements')->nullable();
            $table->text('jd_benefits')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['is_open', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
