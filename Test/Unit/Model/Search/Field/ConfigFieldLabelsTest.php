<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Field;

use Hryvinskyi\AdminActionLog\Model\Search\Field\ConfigFieldLabels;
use Hryvinskyi\AdminActionLog\Model\Search\Field\OptionLabels;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

/**
 * Settings named as Stores › Configuration shows them, and what is shown as stored when it cannot be named
 */
class ConfigFieldLabelsTest extends TestCase
{
    /**
     * Config paths the structure was asked for
     *
     * @var list<string>
     */
    private array $lookups = [];

    public function testNamesASettingByItsGroupAndFieldOnce(): void
    {
        $labels = $this->labels();

        self::assertSame(
            ['catalog/review/active' => 'Product Reviews › Enabled', 'web/loose/field' => 'Loose'],
            $labels->labelsOf(['catalog/review/active', 'web/loose/field', 'gone/path/x'])
        );
        $labels->labelsOf(['catalog/review/active', 'gone/path/x']);

        self::assertSame(
            ['catalog/review/active', 'web/loose/field', 'gone/path/x'],
            $this->lookups,
            'Each path is read once per request; a path with no field is remembered as such'
        );
    }

    public function testNamesAValueByItsOptionAndLeavesWhatHasNoneAsStored(): void
    {
        $labels = $this->labels();

        self::assertSame(
            ['1' => 'Yes', '0' => 'No'],
            $labels->valuesOf('catalog/review/active', ['1', '0', '7', '']),
            'A value no option names, or none at all, is left out'
        );
        self::assertSame([], $labels->valuesOf('web/loose/field', ['1']), 'A field without options');
        self::assertSame([], $labels->valuesOf('gone/path/x', ['1']), 'A path no field shows');
    }

    public function testNamesEachValueOfAMultiselectIncludingOptionsInsideGroups(): void
    {
        $labels = $this->labels();

        self::assertSame(
            ['DK,DE' => 'Denmark, Germany', 'DK,XX' => 'Denmark, XX'],
            $labels->valuesOf('general/country/allow', ['DK,DE', 'DK,XX', 'XX,YY'])
        );
    }

    public function testForgetsWhatItReadWhenTheRequestEnds(): void
    {
        $labels = $this->labels();
        $labels->labelsOf(['catalog/review/active']);
        $labels->_resetState();
        $labels->labelsOf(['catalog/review/active']);

        self::assertSame(['catalog/review/active', 'catalog/review/active'], $this->lookups);
    }

    /**
     * Labels over a structure with a Yes/No field in a group, a field outside a labelled group, and a multiselect
     *
     * @return ConfigFieldLabels
     */
    private function labels(): ConfigFieldLabels
    {
        $fields = [
            'catalog/review/active' => $this->field('Enabled', 'catalog/review/active', 'select', [
                ['value' => 1, 'label' => new Phrase('Yes')],
                ['value' => 0, 'label' => new Phrase('No')],
            ]),
            'web/loose/field' => $this->field('Loose', 'web/loose/field', 'text', null),
            'general/country/allow' => $this->field('Allow Countries', 'general/country/allow', 'multiselect', [
                ['label' => 'Europe', 'value' => [['value' => 'DK', 'label' => 'Denmark']]],
                'DE' => 'Germany',
            ]),
            'gone/path/x' => $this->field('', 'gone/path/x', 'text', null),
        ];
        $groups = [
            'catalog/review' => $this->group('Product Reviews'),
            'web/loose' => $this->group(''),
            'general/country' => $this->group('Country Options'),
        ];

        $structure = $this->createMock(Structure::class);
        $structure->method('getElementByConfigPath')->willReturnCallback(
            function (mixed $path) use ($fields): ?Field {
                $path = is_string($path) ? $path : '';
                $this->lookups[] = $path;

                return $fields[$path] ?? null;
            }
        );
        $structure->method('getElement')->willReturnCallback(
            static fn (mixed $path): ?Group => $groups[is_string($path) ? $path : ''] ?? null
        );

        return new ConfigFieldLabels($structure, new OptionLabels());
    }

    /**
     * A field element
     *
     * @param string $label
     * @param string $path
     * @param string $type
     * @param array<mixed>|null $options Null for a field without options
     * @return Field
     */
    private function field(string $label, string $path, string $type, ?array $options): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getLabel')->willReturn($label);
        $field->method('getPath')->willReturn($path);
        $field->method('getType')->willReturn($type);
        $field->method('hasOptions')->willReturn($options !== null);
        $field->method('getOptions')->willReturn($options ?? []);

        return $field;
    }

    /**
     * A group element, whose label comes back as a phrase the way the structure gives it
     *
     * @param string $label
     * @return Group
     */
    private function group(string $label): Group
    {
        $group = $this->createMock(Group::class);
        $group->method('getLabel')->willReturn(new Phrase($label));

        return $group;
    }
}
