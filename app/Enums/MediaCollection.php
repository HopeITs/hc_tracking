<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a stored file is, as distinct from its format.
 *
 * The value is what lands in `media.collection` (VARCHAR(50)). This is the
 * display category that `FileType` deliberately is NOT: `FileType` is the file
 * extension and the media `path` is built from it, so the two never merge.
 *
 * Values are lower case and casing is part of the value.
 */
enum MediaCollection: string
{
    use HasTranslatedLabel;

    case Proposal = 'proposal';
    case Agreement = 'agreement';
    case Mou = 'mou';
    case Photo = 'photo';
    case Evidence = 'evidence';
    case Other = 'other';
    case FinalReport = 'final_report';
    case FinalReportAcceptance = 'final_report_acceptance';
    case NeedAssessment = 'need assessment';

    /**
     * Whether this collection holds signed funding paperwork.
     *
     * An agreement or MOU can state the budget, so it is agreement data:
     * only someone holding funding-agreements.view may list or open it.
     */
    public function isFundingPaper(): bool
    {
        return in_array($this, [self::Agreement, self::Mou], true);
    }

    /**
     * All media collection values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
