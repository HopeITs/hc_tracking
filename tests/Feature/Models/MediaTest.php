<?php

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a file resolves its owner through the morph map', function () {
    $row = placeRow();
    $media = Media::factory()->create(['mediable_type' => 'proposal_location', 'mediable_id' => $row->id]);

    expect($media->mediable)->toBeInstanceOf(ProposalLocation::class)
        ->and($media->mediable->id)->toBe($row->id);
});

test('a file casts its collection and type to enums', function () {
    $media = Media::factory()->inCollection(MediaCollection::Agreement)->create()->fresh();

    expect($media->collection)->toBe(MediaCollection::Agreement)
        ->and($media->type)->toBeInstanceOf(FileType::class);
});

test('a file reports its uploader', function () {
    $user = User::factory()->create(['name' => 'Layla Haddad']);
    $media = Media::factory()->create(['uploaded_by' => $user->id]);

    expect($media->uploader->name)->toBe('Layla Haddad');
});

test('the collection scope narrows to one kind of file', function () {
    $row = placeRow();

    Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'proposal_location',
        'mediable_id' => $row->id,
    ]);
    Media::factory()->inCollection(MediaCollection::Photo)->create([
        'mediable_type' => 'proposal_location',
        'mediable_id' => $row->id,
    ]);

    expect(Media::query()->inCollection(MediaCollection::Photo)->count())->toBe(1);
});

test('deleting a place through Eloquent removes its files', function () {
    $row = placeRow();
    Media::factory(2)->create(['mediable_type' => 'proposal_location', 'mediable_id' => $row->id]);

    $row->delete();

    expect(DB::table('media')->where('mediable_type', 'proposal_location')->count())->toBe(0);
});

test('deleting one place leaves another place files alone', function () {
    $subject = placeRow();
    $other = placeRow();

    Media::factory()->create(['mediable_type' => 'proposal_location', 'mediable_id' => $subject->id]);
    Media::factory()->create(['mediable_type' => 'proposal_location', 'mediable_id' => $other->id]);

    $subject->delete();

    expect(DB::table('media')->count())->toBe(1)
        ->and((int) DB::table('media')->first()->mediable_id)->toBe($other->id);
});
