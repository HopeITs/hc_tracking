<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a stage template makes when its status is entered (D-63, W-12).
 *
 * Task: an ordinary department task on the proposal. The final report is a
 * task too, since the legacy milestones were removed.
 *
 * Values are lower case and stored in
 * `proposal_stage_task_templates.creates` (VARCHAR(20)).
 */
enum StageTemplateCreates: string
{
    use HasTranslatedLabel;

    case Task = 'task';

    /**
     * All values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
