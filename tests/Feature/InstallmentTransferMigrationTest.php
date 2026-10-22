<?php

use Illuminate\Support\Facades\Schema;

/**
 * T27 found that this migration's down() failed on MariaDB (error 1553): it
 * dropped the unique index the installment foreign key still used.
 */
beforeEach(function () {
    $this->installmentMigration = require glob(database_path('migrations/*_add_source_installment_and_arrival_to_transfers_tracking_table.php'))[0];
});

test('rolling the migration back removes the installment link, and running it again restores it', function () {
    $this->installmentMigration->down();

    expect(Schema::hasColumns('transfers_tracking', ['source', 'installment_id', 'arrival_date']))->toBeFalse()
        ->and(Schema::hasIndex('transfers_tracking', 'transfers_tracking_installment_id_unique'))->toBeFalse();

    $this->installmentMigration->up();

    $foreignKeys = collect(Schema::getForeignKeys('transfers_tracking'))->pluck('name');

    expect(Schema::hasColumns('transfers_tracking', ['source', 'installment_id', 'arrival_date']))->toBeTrue()
        ->and(Schema::hasIndex('transfers_tracking', 'transfers_tracking_installment_id_unique', 'unique'))->toBeTrue()
        ->and($foreignKeys)->toContain('transfers_tracking_installment_id_foreign');
});
