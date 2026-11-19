<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "Needs attention" items one person hid on one proposal page. The
     * item key is the stable key the page gives each item; hiding is
     * per person and never changes the item for anyone else.
     */
    public function up(): void
    {
        if (Schema::hasTable('proposal_attention_dismissals')) {
            throw new RuntimeException('proposal_attention_dismissals already exists; nothing was changed.');
        }

        Schema::create('proposal_attention_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', indexName: 'proposal_attention_dismissals_user_id_foreign')->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained('proposals', indexName: 'proposal_attention_dismissals_proposal_id_foreign')->cascadeOnDelete();
            $table->string('item_key', 120);
            $table->timestamps();

            $table->unique(['user_id', 'proposal_id', 'item_key'], 'proposal_attention_dismissals_user_proposal_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_attention_dismissals');
    }
};
