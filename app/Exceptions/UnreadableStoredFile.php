<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A stored upload that cannot be turned back into its original bytes: the
 * file is gone, its payload is corrupt, or the key that encrypted it is not
 * configured any more (contracts §16).
 *
 * The person sees a friendly page; the log gets the table, row id and key id
 * and nothing else — no path, no payload, no key, no stack trace — so a log
 * reader learns which record to look at and nothing about its contents.
 */
final class UnreadableStoredFile extends RuntimeException
{
    private ?string $table = null;

    private ?int $rowId = null;

    private function __construct(private readonly ?string $keyId)
    {
        parent::__construct('A stored file could not be read.');
    }

    /**
     * The file's bytes could not be read or decrypted with the given key id
     * (NULL for a legacy plaintext file).
     */
    public static function forKey(?string $keyId): self
    {
        return new self($keyId);
    }

    /**
     * Name the record behind the file, for the log line. The caller knows the
     * row; StoredFiles only knows the disk and path.
     */
    public function forRow(string $table, int $rowId): self
    {
        $this->table = $table;
        $this->rowId = $rowId;

        return $this;
    }

    public function report(): void
    {
        Log::error('Stored file could not be read.', [
            'table' => $this->table,
            'id' => $this->rowId,
            'key_id' => $this->keyId,
        ]);
    }

    public function render(): Response
    {
        return response()->view('errors.file-unreadable', status: 500);
    }
}
