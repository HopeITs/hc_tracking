<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Base for models backed by the HR application's database.
 *
 * The HR app owns these tables, so hc-tracking only ever reads them. Writes are
 * refused here, and the `hr` connection additionally opens every session as
 * READ ONLY so query-builder writes are rejected by MySQL itself.
 */
abstract class HrModel extends Model
{
    protected $connection = 'hr';

    /**
     * Create a related model without handing it this model's connection.
     *
     * Eloquent gives a related model with no connection of its own the parent's
     * connection, which would send an HR employee's projects() query to hr_db.
     * Tracking models must stay on the default connection; HR models already
     * name `hr` themselves.
     *
     * @template TRelatedModel of Model
     *
     * @param  class-string<TRelatedModel>  $class
     * @return TRelatedModel
     */
    protected function newRelatedInstance($class): Model
    {
        return new $class;
    }

    /**
     * Refuse to insert or update an HR record.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw new LogicException(static::class.' is read-only: HR data is owned by the HR application.');
    }

    /**
     * Refuse to delete an HR record.
     */
    public function delete(): ?bool
    {
        throw new LogicException(static::class.' is read-only: HR data is owned by the HR application.');
    }
}
