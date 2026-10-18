<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        // Additive upgrade for already-installed Phase 2; retain both unique slots.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE university_email_operations MODIFY status ENUM('prepared','preflight','in_progress','uncertain','confirmed','conflict','failed','cancelled') NOT NULL");
        }
        Schema::table('university_email_operations', function (Blueprint $t): void {
            $t->timestamp('cancelled_at')->nullable();
            $t->integer('cancelled_by_user_id')->nullable();
            $t->foreign('cancelled_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE university_email_operations ADD CONSTRAINT ue_operation_cancellation_check CHECK ((status = 'cancelled' AND creation_slot IS NULL AND active_slot IS NULL AND write_started_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL) OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Cancellation history must be preserved; rollback refused.');
    }
};
