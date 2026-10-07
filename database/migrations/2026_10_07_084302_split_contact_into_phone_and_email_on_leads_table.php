<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16 Sub 07 — `leads.contact` (free text "phone/email") becomes
 * `phone` (digits only, `08…`) + `email`; at least one is required by the
 * Form Requests. Old values are converted: an email → `email`, a number
 * that normalizes to a valid mobile → `phone`, anything else (an Instagram
 * handle, a broken number) is kept in `notes` as "Kontak lama: …" so no
 * data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('client_name')->index();
            $table->string('email')->nullable()->after('phone')->index();
        });

        DB::table('leads')->select(['id', 'contact', 'notes'])->orderBy('id')->each(function (object $lead) {
            $contact = trim((string) $lead->contact);
            $update = [];

            if ($contact === '') {
                return;
            }

            $phone = Phone::normalize($contact);

            if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $update['email'] = mb_strtolower($contact);
            } elseif (Phone::isValid($phone)) {
                $update['phone'] = $phone;
            } else {
                $update['notes'] = trim("Kontak lama: {$contact}\n".($lead->notes ?? ''));
            }

            DB::table('leads')->where('id', $lead->id)->update($update);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('contact');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('contact')->default('')->after('client_name');
        });

        DB::table('leads')->update(['contact' => DB::raw("COALESCE(phone, email, '')")]);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropIndex(['email']);
            $table->dropColumn(['phone', 'email']);
        });
    }
};
