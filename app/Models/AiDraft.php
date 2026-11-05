<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiDraftRating;
use Carbon\CarbonImmutable;
use Database\Factories\AiDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer the AI drafted on a monthly report, kept as written so the
 * trial can show how often drafts were rated helpful and how much officers
 * changed them.
 *
 * @property int $id
 * @property int|null $ai_request_id
 * @property int|null $user_id
 * @property int $monthly_report_answer_id
 * @property string $drafted_text
 * @property AiDraftRating|null $rating
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ai_request_id', 'user_id', 'monthly_report_answer_id', 'drafted_text', 'rating'])]
class AiDraft extends Model
{
    /** @use HasFactory<AiDraftFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => AiDraftRating::class,
        ];
    }

    /**
     * @return BelongsTo<AiRequest, $this>
     */
    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    /**
     * The officer the draft was made for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MonthlyReportAnswer, $this>
     */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(MonthlyReportAnswer::class, 'monthly_report_answer_id');
    }

    /**
     * How much of the draft survives in the answer as it stands, 0-100.
     * 100 means kept as drafted; 0 means rewritten or cleared.
     */
    public function keptPercent(?string $currentAnswer): int
    {
        $current = trim((string) $currentAnswer);

        if ($current === '') {
            return 0;
        }

        similar_text($this->drafted_text, $current, $percent);

        return (int) round($percent);
    }
}
