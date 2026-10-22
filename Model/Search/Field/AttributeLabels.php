<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

use Hryvinskyi\AdminActionLog\Model\Search\Field\Format\AttributeValueFormatInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Attributes of the EAV entities whose changes are recorded, as the admin sees them
 *
 * Searching by a label looks through the admin labels of every attribute of those entities, read in one query the first
 * time a label is searched and kept for the rest of the request, so a search of several words costs one query. Labels
 * are compared case aside. Shown, a code is named by its admin label, and a value is written by the first of the
 * "formats" that supports its attribute (a decimal, an option's label, a date); an attribute none supports shows its
 * values as stored. The attributes a page of changes names are read in one query and kept for the rest of the
 * request. With several entity types, the first one listed that has the code names it.
 */
class AttributeLabels implements FieldLabelSourceInterface, FieldDisplayInterface, ResetAfterRequestInterface
{
    /**
     * @var list<string>
     */
    private readonly array $entityTypes;

    /**
     * Attributes read so far by code, null for a code that names none
     *
     * @var array<string, AbstractAttribute|null>
     */
    private array $attributes = [];

    /**
     * @var list<AttributeValueFormatInterface>
     */
    private array $formats = [];

    /**
     * Admin label of every attribute of the entity types, in lower case, by code; null until a label is searched
     *
     * @var array<string, string>|null
     */
    private ?array $labelList = null;

    /**
     * @param CollectionFactory $collectionFactory
     * @param EavConfig $eavConfig
     * @param array<string> $entityTypes EAV entity type codes, in the order they name a code
     * @param array<AttributeValueFormatInterface> $formats How values are written, in the order they are tried
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly EavConfig $eavConfig,
        array $entityTypes = [],
        array $formats = []
    ) {
        $this->entityTypes = array_values($entityTypes);
        foreach ($formats as $format) {
            $this->addFormat($format);
        }
    }

    /**
     * @inheritDoc
     */
    public function codesLabelled(string $label): array
    {
        $wanted = mb_strtolower(trim($label));

        return $this->codes(static fn (string $attributeLabel): bool => $attributeLabel === $wanted);
    }

    /**
     * @inheritDoc
     */
    public function codesLabelledWith(string $text): array
    {
        $wanted = mb_strtolower(trim($text));

        return $this->codes(
            static fn (string $attributeLabel): bool => $wanted !== '' && str_contains($attributeLabel, $wanted)
        );
    }

    /**
     * @inheritDoc
     */
    public function labelsOf(array $codes): array
    {
        $this->load($codes);
        $labels = [];
        foreach ($codes as $code) {
            $label = $this->attributes[$code]?->getData('frontend_label');
            if (is_string($label) && trim($label) !== '') {
                $labels[$code] = $label;
            }
        }

        return $labels;
    }

    /**
     * @inheritDoc
     */
    public function valuesOf(string $code, array $values): array
    {
        $this->load([$code]);
        $attribute = $this->attributes[$code];
        if ($attribute === null || $values === []) {
            return [];
        }

        foreach ($this->formats as $format) {
            if ($format->supports($attribute)) {
                return $format->format($attribute, $values);
            }
        }

        return [];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->attributes = [];
        $this->labelList = null;
    }

    /**
     * Register a format; anything else in the argument fails here, at construction
     *
     * @param AttributeValueFormatInterface $format
     * @return void
     */
    private function addFormat(AttributeValueFormatInterface $format): void
    {
        $this->formats[] = $format;
    }

    /**
     * Read the attributes of codes not read yet, in one query
     *
     * @param list<string> $codes
     * @return void
     */
    private function load(array $codes): void
    {
        $missing = array_values(array_diff(array_unique($codes), array_keys($this->attributes)));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $code) {
            $this->attributes[$code] = null;
        }

        $typeIds = $this->typeIds();
        if ($typeIds === []) {
            return;
        }

        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('entity_type_id', ['in' => $typeIds])
            ->addFieldToFilter('attribute_code', ['in' => $missing]);
        $ranks = array_flip($typeIds);
        foreach ($collection as $attribute) {
            if (!$attribute instanceof AbstractAttribute) {
                continue;
            }

            $code = (string) $attribute->getAttributeCode();
            $typeId = $this->typeIdOf($attribute);
            $current = $this->attributes[$code] ?? null;
            if (!isset($ranks[$typeId])
                || ($current !== null && ($ranks[$this->typeIdOf($current)] ?? 0) < $ranks[$typeId])
            ) {
                continue;
            }

            $this->attributes[$code] = $attribute;
        }
    }

    /**
     * The entity type id an attribute belongs to
     *
     * @param AbstractAttribute $attribute
     * @return int
     */
    private function typeIdOf(AbstractAttribute $attribute): int
    {
        $typeId = $attribute->getData('entity_type_id');

        return is_numeric($typeId) ? (int) $typeId : 0;
    }

    /**
     * Ids of the configured entity types, in the order listed
     *
     * @return list<int>
     */
    private function typeIds(): array
    {
        $typeIds = [];
        foreach ($this->entityTypes as $entityType) {
            $typeId = $this->eavConfig->getEntityType($entityType)->getId();
            if (is_numeric($typeId)) {
                $typeIds[(int) $typeId] = (int) $typeId;
            }
        }

        return array_values($typeIds);
    }

    /**
     * Codes of the attributes whose label, in lower case, the test accepts
     *
     * @param \Closure(string):bool $accepts
     * @return list<string>
     */
    private function codes(\Closure $accepts): array
    {
        $codes = [];
        foreach ($this->labelList() as $code => $label) {
            if ($accepts($label)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The admin label of every attribute of the entity types that has one, in lower case, by code; read once
     *
     * @return array<string, string>
     */
    private function labelList(): array
    {
        if ($this->labelList !== null) {
            return $this->labelList;
        }

        $this->labelList = [];
        $typeIds = $this->typeIds();
        if ($typeIds === []) {
            return $this->labelList;
        }

        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('entity_type_id', ['in' => $typeIds])
            ->addFieldToFilter('frontend_label', ['notnull' => true]);
        foreach ($collection->getData() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = $row['attribute_code'] ?? null;
            $label = $row['frontend_label'] ?? null;
            if (is_string($code) && $code !== '' && is_string($label) && trim($label) !== ''
                && !isset($this->labelList[$code])
            ) {
                $this->labelList[$code] = mb_strtolower(trim($label));
            }
        }

        return $this->labelList;
    }
}
