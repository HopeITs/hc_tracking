<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A proposal funded through an intermediary: the back donor stays in
 * proposals.donor_id; the Main Partner (the organisation we sign with and
 * report to) is one donor-library row, and any other Partners are reference
 * rows in proposal_partner. Both restrict on delete so a library entry in use
 * cannot silently vanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->foreignId('main_partner_id')->nullable()->after('donor_id')->constrained('donors')->restrictOnDelete();
        });

        Schema::create('proposal_partner', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->foreignId('donor_id')->constrained('donors')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['proposal_id', 'donor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_partner');

        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('main_partner_id');
        });
    }
};
