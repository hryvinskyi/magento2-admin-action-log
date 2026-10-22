<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Field;

use Hryvinskyi\AdminActionLog\Model\Search\Field\OptionLabels;
use Hryvinskyi\AdminActionLog\Model\Search\Field\StaticFields;
use Hryvinskyi\AdminActionLog\Model\Search\Field\Value\OptionSourceNames;
use Hryvinskyi\AdminActionLog\Model\Search\Field\Value\ValueNamesInterface;
use Magento\Framework\Data\OptionSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Fields kept by id shown by name, over a recording fake of the names
 */
class StaticFieldsTest extends TestCase
{
    /**
     * Id lists the names were asked for, one entry per lookup
     *
     * @var list<list<string>>
     */
    private array $lookups = [];

    public function testLabelsOnlyTheFieldsGivenALabel(): void
    {
        self::assertSame(
            ['attribute_set_id' => 'Attribute Set'],
            $this->fields()->labelsOf(['attribute_set_id', 'category_ids', 'price'])
        );
    }

    public function testFindsALabelledFieldByItsLabelWholeOrInPartCaseAside(): void
    {
        $fields = $this->fields();

        self::assertSame(['attribute_set_id'], $fields->codesLabelled(' attribute set '));
        self::assertSame([], $fields->codesLabelled('Attribute'), 'A label is matched whole');
        self::assertSame(['attribute_set_id'], $fields->codesLabelledWith('SET'));
        self::assertSame([], $fields->codesLabelledWith(''), 'No text finds nothing');
        self::assertSame([], $fields->codesLabelled('category_ids'), 'A field without a label is not found by one');
    }

    public function testNamesSingleIdsAndLeavesOneThatNamesNothingAsStored(): void
    {
        self::assertSame(
            ['9' => 'Top', '10' => 'Bottom'],
            $this->fields()->valuesOf('attribute_set_id', ['9', '10', '77'])
        );
        self::assertSame([['9', '10', '77']], $this->lookups);
    }

    public function testNamesEachIdOfAListKeepingTheListAndWritesAGoneIdByIt(): void
    {
        $shown = $this->fields()->valuesOf('category_ids', ['[]', '["42","7"]', '[7]', 'not a list', '{"a":1}']);

        self::assertSame(
            ['["42","7"]' => '["Gear › Bags & Luggage","#7"]', '[7]' => '["#7"]'],
            $shown,
            'An empty list, and anything not a list of ids, is shown as stored'
        );
        self::assertSame([['42', '7']], $this->lookups, 'Every id of the values asked together, once');
    }

    public function testShowsAFieldItDoesNotKnowAsStored(): void
    {
        self::assertSame([], $this->fields()->valuesOf('price', ['1']));
        self::assertSame([], $this->lookups);
    }

    public function testRefusesAFieldWithoutNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StaticFields(['type_id' => ['label' => 'Product Type']]);
    }

    public function testOptionSourceNamesReadTheSourceOnceAndNameOnlyTheIdsAsked(): void
    {
        $source = $this->createMock(OptionSourceInterface::class);
        $source->expects(self::once())->method('toOptionArray')->willReturn([
            ['value' => 'simple', 'label' => 'Simple Product'],
            ['value' => 'virtual', 'label' => 'Virtual Product'],
        ]);
        $names = new OptionSourceNames($source, new OptionLabels());

        self::assertSame(['virtual' => 'Virtual Product'], $names->namesOf(['virtual', 'gone']));
        self::assertSame(['simple' => 'Simple Product'], $names->namesOf(['simple']));
        self::assertSame([], $names->namesOf([]));
    }

    /**
     * An attribute set field and a category list over names that know sets 9 and 10 and category 42
     *
     * @return StaticFields
     */
    private function fields(): StaticFields
    {
        $names = $this->createMock(ValueNamesInterface::class);
        $names->method('namesOf')->willReturnCallback(function (array $ids): array {
            $ids = array_values(array_filter($ids, 'is_string'));
            $this->lookups[] = $ids;

            return array_intersect_key(
                ['9' => 'Top', '10' => 'Bottom', '42' => 'Gear › Bags & Luggage'],
                array_flip($ids)
            );
        });

        return new StaticFields([
            'attribute_set_id' => ['label' => 'Attribute Set', 'names' => $names],
            'category_ids' => ['names' => $names, 'list' => true],
        ]);
    }
}
