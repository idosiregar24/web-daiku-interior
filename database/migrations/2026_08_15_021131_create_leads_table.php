<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.1 (CRM / Presales) + §5.1 (Database Schema).
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('contact'); // phone/email
            $table->string('source'); // Instagram, TikTok, Referral, Walk-in, WhatsApp, Marketplace, Iklan Sosmed, Website
            $table->string('priority'); // HOT/WARM/COLD — PRD §4.1
            $table->string('category')->nullable(); // RESIDENTIAL, KOMERSIAL, DEVELOPER, KONTRAKTOR, LAINNYA
            $table->string('service')->nullable(); // layanan — BUILD INTERIOR RUMAH/CAFE/KANTOR/TOKO, dst
            $table->string('city')->nullable();
            $table->string('gender')->nullable();
            $table->text('order_detail')->nullable();
            $table->string('status')->default('FOLLOW_UP'); // LeadStatus
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->date('follow_up_date')->nullable();
            // Wajib diisi saat status berubah ke LOST — divalidasi di LeadService, bukan di sini.
            $table->text('lost_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
