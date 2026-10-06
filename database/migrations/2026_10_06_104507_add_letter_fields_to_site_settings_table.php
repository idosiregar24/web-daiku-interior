<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 15 Sub 01 — what the letter-style PDFs and the client's link
     * print beyond the existing address/phone/email: Instagram, the legal
     * name bank transfers go to, the footer line, who signs (name, title,
     * signature image) and the default "Catatan" per RAB type.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('company_instagram', 100)->nullable()->after('company_email');
            $table->string('company_legal_name', 150)->nullable()->after('company_instagram');
            $table->string('letter_footer', 200)->nullable()->after('company_legal_name');
            $table->string('signer_name', 100)->nullable()->after('letter_footer');
            $table->string('signer_title', 100)->nullable()->after('signer_name');
            $table->string('signature_path')->nullable()->after('login_image_path');
            $table->text('note_survey')->nullable();
            $table->text('note_desain')->nullable();
            $table->text('note_proyek')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn([
                'company_instagram',
                'company_legal_name',
                'letter_footer',
                'signer_name',
                'signer_title',
                'signature_path',
                'note_survey',
                'note_desain',
                'note_proyek',
            ]);
        });
    }
};
