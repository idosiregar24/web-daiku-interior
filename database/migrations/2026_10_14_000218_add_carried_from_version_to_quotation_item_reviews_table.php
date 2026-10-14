<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K7 — an item ✔ in an earlier version and unchanged since
     * (same section, name, P, T/L, volume, unit, price) is not checked
     * again: its ✔ is copied into the new version's review with
     * `carried_from_version` = the version the PM really checked it in.
     * Items are rewritten on every RAB save, so they are matched by
     * content: `item_fingerprint` is that content's hash at review time
     * (QuotationItem::fingerprint()). Older rows have neither (null).
     */
    public function up(): void
    {
        Schema::table('quotation_item_reviews', function (Blueprint $table) {
            $table->unsignedInteger('carried_from_version')->nullable()->after('note');
            $table->string('item_fingerprint', 64)->nullable()->after('carried_from_version');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_item_reviews', function (Blueprint $table) {
            $table->dropColumn(['carried_from_version', 'item_fingerprint']);
        });
    }
};
