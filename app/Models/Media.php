<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FileType;
use App\Enums\MediaCollection;
use Carbon\CarbonImmutable;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a project or a milestone.
 *
 * @property int $id
 * @property string $mediable_type
 * @property int $mediable_id
 * @property MediaCollection $collection
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property FileType $type
 * @property CarbonImmutable|null $uploaded_at
 * @property int $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'mediable_type',
    'mediable_id',
    'collection',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size',
    'type',
    'uploaded_at',
    'uploaded_by',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /**
     * Eloquent would pluralize this to `medias`.
     *
     * @var string
     */
    protected $table = 'media';

    /**
     * Mirror the column defaults so a model that has not been round-tripped
     * through the database still reports a usable disk and collection.
     *
     * Without this, reading `$media->disk` on an unsaved model raises
     * MissingAttributeException under strict mode — the same trap
     * Account::$is_active documents.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'collection' => 'other',
        'disk' => 'local',
    ];

    /**
     * The proposal, project or milestone this file is attached to.
     *
     * Resolves through the morph map, so `mediable_type` holds 'proposal',
     * 'project' or 'milestone', never a class name. It can resolve to NULL: `mediable_id`
     * carries no foreign key, because a polymorphic column cannot.
     *
     * @return MorphTo<Model, $this>
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The staff member who uploaded this file.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Whether a real file sits on disk behind this row.
     *
     * Seeded demo rows never had one written, so they are shown as reference
     * only rather than offered for a preview that would 404.
     */
    public function isStored(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Whether the browser can render this file type inline (PDF or image).
     */
    public function isPreviewable(): bool
    {
        return in_array($this->type, [FileType::Pdf, FileType::Jpg, FileType::Png], true);
    }

    /**
     * Limit the query to one collection.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inCollection(Builder $query, MediaCollection $collection): void
    {
        $query->where('collection', $collection->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collection' => MediaCollection::class,
            'type' => FileType::class,
            'size' => 'integer',
            'uploaded_at' => 'date',
        ];
    }
}
