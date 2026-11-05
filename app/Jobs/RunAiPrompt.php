<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\AiPromptFinished;
use App\Models\User;
use App\Support\Ai\AiGateway;
use App\Support\Ai\HcAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one AI prompt on the "ai" queue, so a 10-60 s model call never blocks
 * a page. The answer is kept for an hour under a request key and pushed to
 * the user live; the page can also ask for it by key if the push is missed.
 */
final class RunAiPrompt implements ShouldQueue
{
    use Queueable;

    /**
     * How long a finished answer can still be collected by its key.
     */
    public const int RESULT_TTL_SECONDS = 3600;

    public int $tries = 2;

    public int $timeout = 150;

    /**
     * @var list<int>
     */
    public array $backoff = [15];

    /**
     * @param  'default'|'fast'  $tier
     */
    public function __construct(
        public HcAgent $agent,
        public string $prompt,
        public string $feature,
        public int $userId,
        public string $requestKey,
        public string $tier = 'default',
    ) {
        $this->onConnection((string) config('ai.queue_connection', 'ai'));
        $this->onQueue('ai');
    }

    /**
     * Queue a prompt for a user and return the key its answer will arrive under.
     *
     * @param  'default'|'fast'  $tier
     */
    public static function start(HcAgent $agent, string $prompt, string $feature, User $user, string $tier = 'default'): string
    {
        $requestKey = (string) Str::uuid7();

        Cache::put(self::cacheKey($requestKey), ['status' => 'pending', 'user_id' => $user->id, 'text' => null, 'request_id' => null], self::RESULT_TTL_SECONDS);

        self::dispatch($agent, $prompt, $feature, $user->id, $requestKey, $tier);

        return $requestKey;
    }

    /**
     * The state of a request, only for the user who started it.
     *
     * @return array{status: 'pending'|'done'|'failed', text: string|null, request_id: int|null}|null
     */
    public static function resultFor(string $requestKey, User $user): ?array
    {
        $stored = Cache::get(self::cacheKey($requestKey));

        if (! is_array($stored) || ($stored['user_id'] ?? null) !== $user->id) {
            return null;
        }

        return ['status' => $stored['status'], 'text' => $stored['text'], 'request_id' => $stored['request_id'] ?? null];
    }

    public function handle(AiGateway $gateway): void
    {
        $user = User::find($this->userId);
        $response = $gateway->prompt($this->agent, $this->prompt, $this->feature, $user, $this->tier);
        $text = $gateway->answerText($response);

        $this->store('done', $text, $gateway->lastRequestId);

        $this->push(true, $text);
    }

    /**
     * After the last try: tell the page, without exposing the provider error.
     * The error itself is already in ai_requests.
     */
    public function failed(?Throwable $exception): void
    {
        $this->store('failed', null);

        $this->push(false, null);
    }

    /**
     * A dead socket must not turn a good answer into a failure: the page
     * still collects it by key, so the push failure is only reported.
     */
    private function push(bool $succeeded, ?string $text): void
    {
        try {
            AiPromptFinished::dispatch($this->userId, $this->requestKey, $succeeded, $text);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function store(string $status, ?string $text, ?int $aiRequestId = null): void
    {
        Cache::put(self::cacheKey($this->requestKey), ['status' => $status, 'user_id' => $this->userId, 'text' => $text, 'request_id' => $aiRequestId], self::RESULT_TTL_SECONDS);
    }

    private static function cacheKey(string $requestKey): string
    {
        return 'ai-prompt:'.$requestKey;
    }
}
