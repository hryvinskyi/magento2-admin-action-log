<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

/**
 * The admin page a kind of tracked record opens on: its route, the ACL resource it needs, and the route parameters of
 * each record that can still be opened
 *
 * Added to SubjectLinks' "editPages" under the kind's code. Most kinds open on their own edit page (EditPage); a kind
 * without one opens on a page the record names (OwnedRecordPage, ConfigSectionPage).
 */
interface RecordPageInterface
{
    /**
     * The ACL resource an admin needs to open the page
     *
     * @return string
     */
    public function getAclResource(): string;

    /**
     * The admin route path of the page
     *
     * @return string
     */
    public function getRoute(): string;

    /**
     * The route parameters of each record that can still be opened, keyed by its id; asked for a page at once
     *
     * @param list<string> $ids
     * @return array<string, array<string, string>>
     */
    public function paramsOf(array $ids): array;
}
