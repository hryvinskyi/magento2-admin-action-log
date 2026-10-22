<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;

/**
 * Records a change of a tracked entity that was not made by saving or deleting its model, such as a quantity a stock
 * service writes to a store of its own
 *
 * The change is held to the rules of the changes of saved models:
 * - nothing is recorded while entity changes or the journal are switched off, for an entity whose changes are
 *   switched off, for an actor whose changes are not chosen, or for an entity code nobody tracks;
 * - two values that mean the same by the same comparison are no change, and a change in which no field changed is
 *   not recorded;
 * - secret fields are masked before anything is stored;
 * - the entity's mode decides whether unchanged fields are kept too, and its retention how long the change is kept;
 * - the change is named the way the entity's own changes name it ("Product 24-MB01 · Joust Duffle Bag"), or by the
 *   entity's label and id when the record cannot be read. Only the fields that name a record are read, for all the
 *   changes of one call together.
 *
 * Call it once the change has been written. Record the changes of one write with one recordAll() call: the records
 * they name are read in one query, and each change is recorded on its own, so one that cannot be recorded never keeps
 * the others from being. The record is written through the default connection right away: inside
 * an open transaction it becomes part of that transaction, so it is committed or rolled back with the change it
 * describes, and a change that was rolled back leaves no record.
 *
 * Recording never fails the caller: a failure to record is logged and the call returns.
 *
 * @api
 * @since 1.1.0
 */
interface EntityChangeRecorderInterface
{
    /**
     * Record the change, or nothing when the rules say so
     *
     * @param EntityChangeInterface $change
     * @return void
     */
    public function record(EntityChangeInterface $change): void;

    /**
     * Record each change, or nothing for those the rules leave out
     *
     * @param list<EntityChangeInterface> $changes
     * @return void
     */
    public function recordAll(array $changes): void;
}
