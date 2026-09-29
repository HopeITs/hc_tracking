<?php

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Rules\SecureUpload;
use App\Support\UploadName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function passesSecureUpload(UploadedFile $file): bool
{
    return Validator::make(['file' => $file], ['file' => [new SecureUpload]])->passes();
}

function messageFor(UploadedFile $file): string
{
    return Validator::make(['file' => $file], ['file' => [new SecureUpload]])->errors()->first('file');
}

test('genuine pdf, image and office uploads pass', function (string $name) {
    expect(passesSecureUpload(validUpload($name)))->toBeTrue();
})->with(['a.pdf', 'a.png', 'a.jpg', 'a.docx', 'a.xlsx']);

test('a file whose bytes are not the format its extension claims is rejected', function (UploadedFile $file) {
    expect(passesSecureUpload($file))->toBeFalse()
        ->and(messageFor($file))->toContain('genuine');
})->with([
    'exe renamed to pdf' => [fn () => UploadedFile::fake()->createWithContent('a.pdf', "MZ\x90\x00 not a pdf")],
    'php renamed to png' => [fn () => UploadedFile::fake()->createWithContent('a.png', '<?php echo 1;')],
    'html renamed to jpg' => [fn () => UploadedFile::fake()->createWithContent('a.jpg', '<html><script>alert(1)</script></html>')],
    'png renamed to jpg' => [fn () => UploadedFile::fake()->createWithContent('a.jpg', validUpload('a.png')->getContent())],
    'text renamed to docx' => [fn () => UploadedFile::fake()->createWithContent('a.docx', 'plain text')],
    'xlsx renamed to docx' => [fn () => UploadedFile::fake()->createWithContent('a.docx', validUpload('a.xlsx')->getContent())],
    'zero-filled pdf' => [fn () => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
]);

test('an office file with a macro project is rejected', function () {
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<x/>');
    $zip->addFromString('word/vbaProject.bin', 'macro');
    $zip->close();
    $file = UploadedFile::fake()->createWithContent('a.docx', (string) file_get_contents($path));
    unlink($path);

    expect(passesSecureUpload($file))->toBeFalse()
        ->and(messageFor($file))->toContain('scripts or macros');
});

test('upload names lose control characters, separators and quotes but keep Arabic letters', function () {
    expect(UploadName::clean("report\r\nX-Evil: 1.pdf"))->toBe('reportX-Evil 1.pdf')
        ->and(UploadName::clean('..\\..\\etc/passwd"'))->toBe('etcpasswd')
        ->and(UploadName::clean('تقرير الميزانية.pdf'))->toBe('تقرير الميزانية.pdf')
        ->and(UploadName::clean("\r\n"))->toBe('file')
        ->and(mb_strlen(UploadName::clean(str_repeat('a', 400).'.pdf')))->toBeLessThanOrEqual(150)
        ->and(UploadName::clean(str_repeat('a', 400).'.pdf'))->toEndWith('.pdf');
});

test('a stored name containing CR/LF is streamed without splitting the response headers', function (string $route) {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $attachment = TaskAttachment::factory()->create([
        'task_id' => $task->id,
        'type' => 'pdf',
        'path' => 'task-attachments/'.$task->id.'/legacy.pdf',
        'original_name' => "evil\r\nSet-Cookie: x=1.pdf",
    ]);
    Storage::disk('local')->put($attachment->path, validUpload('a.pdf')->getContent());

    $response = $this->actingAs($user)->get(route($route, [$task, $attachment]))->assertOk();

    expect($response->headers->get('Content-Disposition'))->not->toContain("\n")->not->toContain("\r")
        ->and(collect($response->headers->getCookies())->map->getName()->all())->not->toContain('x')
        ->and($response->headers->get('Cross-Origin-Resource-Policy') ?? 'same-origin')->toBe('same-origin');
})->with(['tasks.files.download', 'tasks.files.preview']);

test('the task upload form refuses a fake pdf with a clear message and stores nothing', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();

    Livewire\Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('openCreateFileModal')
        ->set('newFile', UploadedFile::fake()->createWithContent('invoice.pdf', 'MZ not a pdf'))
        ->set('fileDate', '2026-03-31')
        ->call('saveFile')
        ->assertHasErrors(['newFile']);

    expect(TaskAttachment::query()->where('task_id', $task->id)->count())->toBe(0);
});
