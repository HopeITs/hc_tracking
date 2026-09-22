<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A department from the HR database (hchr_departments).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property string|null $email
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrDepartment extends HrModel
{
    protected $table = 'departments';
}
