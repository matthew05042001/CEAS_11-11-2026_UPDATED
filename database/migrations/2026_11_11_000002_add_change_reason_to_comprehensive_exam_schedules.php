<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
            $table->text('change_reason')->nullable()->after('teacher_assigned');
        });
    }

    public function down(): void
    {
        Schema::table('comprehensive_exam_schedules', function (Blueprint $table) {
            $table->dropColumn('change_reason');
        });
    }
};
