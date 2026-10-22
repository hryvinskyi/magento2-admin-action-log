<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Value;

use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Categories named by their path from the top of their store's tree, as the admin's category picker shows them, then
 * their id: "Gear › Bags & Luggage #42"
 *
 * The id keeps two categories of the same name, or a name of digits alone, apart. The tree's own roots (the catalog
 * root and each store's root category) are left out of a path; a root asked for itself is named alone, with its id.
 * Names are the admin's (all store views). The categories asked for and their parents are read in one query and kept
 * for the rest of the request; an id that names no category any more is left out.
 */
class CategoryPaths implements ValueNamesInterface, ResetAfterRequestInterface
{
    private const SEPARATOR = ' › ';
    private const WITH_ID = '%1$s #%2$s';
    private const FIRST_NAMED_LEVEL = 2;
    private const ADMIN_STORE = 0;

    /**
     * Paths read so far by id, null for an id that names no category
     *
     * @var array<string, string|null>
     */
    private array $paths = [];

    /**
     * @param CategoryResource $resource
     */
    public function __construct(
        private readonly CategoryResource $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function namesOf(array $ids): array
    {
        $this->read(array_values(array_filter(
            array_diff(array_unique($ids), array_map('strval', array_keys($this->paths))),
            static fn (string $id): bool => ctype_digit($id)
        )));

        $names = [];
        foreach ($ids as $id) {
            $path = $this->paths[$id] ?? null;
            if ($path !== null) {
                $names[$id] = $path;
            }
        }

        return $names;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->paths = [];
    }

    /**
     * Read the paths of categories not read yet, with the names of their parents, in one query
     *
     * @param list<string> $ids
     * @return void
     */
    private function read(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            $this->paths[$id] = null;
        }

        $name = $this->resource->getAttribute('name');
        if (!$name instanceof AbstractAttribute) {
            return;
        }

        $connection = $this->resource->getConnection();
        $entityTable = $this->resource->getEntityTable();
        $link = $this->resource->getLinkField();
        $select = $connection->select()
            ->from(['category' => $entityTable], ['id' => 'category.entity_id'])
            ->join(
                ['ancestor' => $entityTable],
                "CONCAT(category.path, '/') LIKE CONCAT(ancestor.path, '/%')",
                ['level' => 'ancestor.level']
            )
            ->join(
                ['name' => $name->getBackendTable()],
                implode(' AND ', [
                    sprintf('name.%1$s = ancestor.%1$s', $link),
                    $connection->quoteInto('name.attribute_id = ?', (int) $name->getAttributeId()),
                    $connection->quoteInto('name.store_id = ?', self::ADMIN_STORE),
                ]),
                ['name' => 'name.value']
            )
            ->where('category.entity_id IN (?)', array_map('intval', $ids))
            ->where(
                'ancestor.level >= ? OR ancestor.entity_id = category.entity_id',
                self::FIRST_NAMED_LEVEL
            )
            ->order(['category.entity_id ASC', 'ancestor.level ASC']);

        $parts = [];
        foreach ($connection->fetchAll($select) as $row) {
            if (is_array($row) && is_scalar($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                $parts[(string) $row['id']][] = $row['name'];
            }
        }

        foreach ($parts as $id => $names) {
            $this->paths[(string) $id] = sprintf(self::WITH_ID, implode(self::SEPARATOR, $names), $id);
        }
    }
}
