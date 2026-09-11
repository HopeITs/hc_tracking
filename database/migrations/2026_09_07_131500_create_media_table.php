<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One polymorphic store for every uploaded file.
     *
     * Projects carry proposal PDFs, signed agreements, MoUs and photos;
     * milestones carry evidence. These are the same thing attached to two
     * owners, so this is one table with a morphTo rather than `project_media`
     * plus `milestone_media`, which would have duplicated every column and
     * every upload path.
     *
     * `mediable_type` is VARCHAR(50), so the morph map registers short keys
     * ('project', 'milestone'), never class names. Registering them widens the
     * map from two entries to four, and
     * tests/Feature/Models/MorphMapTest.php is updated to match: that test
     * exists precisely so adding a morph is a visible, deliberate change.
     *
     * Like `plan_indicators.source_id`, `mediable_id` carries NO foreign key —
     * a polymorphic column cannot — so nothing at the database level removes
     * these rows when an owner goes. MediaOwnerObserver handles the Eloquent
     * path, including the hops through the database cascades on
     * `milestones.project_id` and `milestones.parent_milestone_id` that fire no
     * model events. See .ai/rules/observers.md.
     *
     * `disk` is stored per row rather than read from config at display time:
     * files already written to one disk must not become unreachable because a
     * later deploy changed FILESYSTEM_DISK. It defaults to 'local' because no
     * cloud driver is installed — league/flysystem-aws-s3-v3 is absent, so the
     * s3 disk in config/filesystems.php would fatal if used today.
     *
     * `type` is the FileType extension and `collection` is the display
     * category. They are separate on purpose: the stored path is built from the
     * extension, so it can never disagree with the file on disk, while the
     * category answers "is this the signed agreement or a site photo".
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('mediable_type', 50);
            $table->unsignedBigInteger('mediable_id');
            $table->string('collection', 50)->default('other');
            $table->string('disk', 50)->default('local');
            $table->text('path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('type', 50);
            $table->date('uploaded_at')->useCurrent();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id']);
            $table->index('collection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
