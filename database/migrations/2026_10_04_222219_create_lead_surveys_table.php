<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 Sub 2 (decision #3) — site surveys scheduled by Marketing,
     * possibly more than once per lead. Inside Pekanbaru: free,
     * DIJADWALKAN → SELESAI. Outside: needs a paid RAB Jasa Survey first —
     * MENUNGGU_BAYAR until Finance verifies the invoice (Sub 6), then SIAP
     * → SELESAI. `quotation_id` links that RAB (filled by Sub 3).
     */
    public function up(): void
    {
        Schema::create('lead_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->dateTime('scheduled_at');
            $table->text('address')->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->boolean('is_outside_pekanbaru')->default(false);
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20); // App\Enums\LeadSurveyStatus
            $table->text('result_note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'sequence']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_surveys');
    }
};
