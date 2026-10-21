<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Block\Adminhtml\System\Config;

use Hryvinskyi\AdminActionLog\Model\Source\DiffModeOptions;
use Hryvinskyi\AdminActionLog\Model\Source\TrackedEntityOptions;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * The per-entity settings table: entity, what a change keeps, days to keep it
 */
class EntityTypeSettings extends AbstractFieldArray
{
    /**
     * @var array<string, OptionsColumn>
     */
    private array $renderers = [];

    /**
     * @var array<string, OptionSourceInterface>
     */
    private readonly array $selectColumns;

    /**
     * @param Context $context
     * @param TrackedEntityOptions $entityOptions
     * @param DiffModeOptions $modeOptions
     * @param SecureHtmlRenderer $secureRenderer
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        TrackedEntityOptions $entityOptions,
        DiffModeOptions $modeOptions,
        SecureHtmlRenderer $secureRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureRenderer);
        $this->selectColumns = ['entity_type' => $entityOptions, 'diff_mode' => $modeOptions];
    }

    /**
     * @inheritDoc
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('entity_type', [
            'label' => __('Entity'),
            'renderer' => $this->renderer('entity_type'),
        ]);
        $this->addColumn('diff_mode', [
            'label' => __('Record'),
            'renderer' => $this->renderer('diff_mode'),
        ]);
        $this->addColumn('retention_days', [
            'label' => __('Keep (days)'),
            'class' => 'validate-digits validate-greater-than-zero',
            'style' => 'width: 80px',
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Entity');
    }

    /**
     * Mark the saved option of each select as selected
     *
     * @param DataObject $row
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $attributes = [];
        foreach (array_keys($this->selectColumns) as $column) {
            $value = $row->getData($column);
            if (is_scalar($value) && (string) $value !== '') {
                $hash = $this->renderer($column)->calcOptionHash((string) $value);
                $attributes['option_' . $hash] = 'selected="selected"';
            }
        }

        $row->setData('option_extra_attrs', $attributes);
    }

    /**
     * The select cell of a column, built once
     *
     * @param string $column
     * @return OptionsColumn
     * @throws LocalizedException
     */
    private function renderer(string $column): OptionsColumn
    {
        if (isset($this->renderers[$column])) {
            return $this->renderers[$column];
        }

        return $this->renderers[$column] = $this->getLayout()->createBlock(
            OptionsColumn::class,
            '',
            ['optionSource' => $this->selectColumns[$column], 'data' => ['is_render_to_js_template' => true]]
        );
    }
}
