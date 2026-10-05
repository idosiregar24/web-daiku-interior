<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decisions #13–#14 — the link Marketing sends the client.
     * One row per "Kirim ke Client", tied to the version it was sent for,
     * so a link from an older version still opens and says "Penawaran ini
     * sudah diperbarui" instead of showing the newer offer. The token is a
     * 48-character random string (Str::random) — the only credential the
     * public page has. Append-only (App\Models\QuotationShareLink).
     *
     * The client's approval itself — when, from which IP / device, through
     * which link — is stored on the quotation.
     */
    public function up(): void
    {
        Schema::create('quotation_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('token', 64)->unique();
            $table->foreignId('sent_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['quotation_id', 'version']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('client_approved_at')->nullable()->after('sent_at');
            $table->string('client_approved_ip', 45)->nullable()->after('client_approved_at');
            $table->string('client_approved_user_agent', 500)->nullable()->after('client_approved_ip');
            $table->foreignId('client_approved_link_id')->nullable()->after('client_approved_user_agent')
                ->constrained('quotation_share_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_approved_link_id');
            $table->dropColumn(['client_approved_at', 'client_approved_ip', 'client_approved_user_agent']);
        });

        Schema::dropIfExists('quotation_share_links');
    }
};
