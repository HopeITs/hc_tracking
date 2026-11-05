<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * The single door every AI feature goes through.
 *
 * It picks the provider and model from config (Ollama for the trial, Claude
 * later, with no feature code changing), applies the timeout, and writes one
 * ai_requests row per call, succeeded or failed.
 */
class AiGateway
{
    /**
     * The ledger row of the latest call this gateway made, so a caller can
     * link what it stores (an AI draft) to the call that produced it.
     */
    public ?int $lastRequestId = null;

    /**
     * Send a prompt to an agent and record the call in the usage ledger.
     *
     * @param  'default'|'fast'  $tier  "fast" for short summaries, "default" for drafting.
     *
     * @throws Throwable when the provider fails; the failure is logged first.
     */
    public function prompt(Agent $agent, string $prompt, string $feature, ?User $user = null, string $tier = 'default'): AgentResponse
    {
        $provider = (string) config('ai.default');
        $model = $this->modelFor($tier);
        $startedAt = hrtime(true);

        try {
            $response = $agent->prompt($prompt, provider: $provider, model: $model, timeout: (int) config('ai.timeout'));
        } catch (Throwable $exception) {
            $this->record($feature, $user, $provider, $model, $startedAt, AiRequestStatus::Failed, error: $exception->getMessage());

            throw $exception;
        }

        $this->record(
            $feature,
            $user,
            $response->meta->provider ?? $provider,
            $response->meta->model ?? $model,
            $startedAt,
            AiRequestStatus::Succeeded,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
        );

        return $response;
    }

    /**
     * The answer without any reasoning a local model wrote into its reply.
     *
     * Some Ollama builds ignore "think: false" and put their reasoning before a
     * closing </think> tag; everything up to that tag is dropped.
     */
    public function answerText(AgentResponse $response): string
    {
        $text = $response->text;
        $closingTag = strrpos($text, '</think>');

        if ($closingTag !== false) {
            $text = substr($text, $closingTag + strlen('</think>'));
        }

        return trim($text);
    }

    /**
     * The model configured for a tier, falling back to the default tier.
     */
    public function modelFor(string $tier): string
    {
        return (string) (config("ai.models.{$tier}") ?? config('ai.models.default'));
    }

    private function record(
        string $feature,
        ?User $user,
        string $provider,
        string $model,
        int|float $startedAt,
        AiRequestStatus $status,
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?string $error = null,
    ): void {
        $this->lastRequestId = AiRequest::create([
            'user_id' => $user?->id,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'error' => $error === null ? null : Str::limit($error, 497),
        ])->id;
    }
}
