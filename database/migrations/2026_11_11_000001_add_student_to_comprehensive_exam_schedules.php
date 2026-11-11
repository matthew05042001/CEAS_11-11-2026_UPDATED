<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
            $table->foreignId('student_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
        });
    }
};
