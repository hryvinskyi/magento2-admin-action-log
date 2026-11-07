<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api;

/**
 * Reads, in one go, what telling the fields of many entities of one kind would otherwise read one entity at a time
 *
 * A write that changes many entities at once (an import of products' stock) is recorded change by change, and a
 * tracked entity or a part of it may need something of its own for each entity to tell its fields (whether a product
 * keeps its stock elsewhere). Before such a write is recorded, the entities it changed are announced here, so the
 * answers are read for all of them together and each entity's question is then answered without a query.
 *
 * Register an implementation under the entity's code, then an item name, in the "readAheads" argument of the tracked
 * entity pool. The pool refuses a read-ahead of an entity it does not track.
 *
 * A read-ahead only reads: it changes nothing an entity's fields depend on. What it cannot read in one go is left to
 * be read one entity at a time, as it would be without it. It never fails the write being recorded: the pool tells
 * each read-ahead on its own, logs one that throws, whatever it throws, and carries on, so an implementation need not
 * catch a failure it has nothing better to do with than to log.
 *
 * @api
 * @since 1.4.0
 */
interface TrackedEntityReadAheadInterface
{
    /**
     * Read what telling the fields of these entities will need
     *
     * @param list<string> $entityIds Ids of entities of the kind the read-ahead is registered for
     * @return void
     */
    public function readAhead(array $entityIds): void;
}
