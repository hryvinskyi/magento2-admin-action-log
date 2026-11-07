<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\ConfigHistory;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\ValueCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Markup appended to a configuration row lands at the end of the row's own value cell, however much markup of its
 * own the cell holds, and a row without a value cell it can pair gets nothing
 */
class ValueCellTest extends TestCase
{
    private const LABEL = '<td class="label"><label for="x"><span>X</span></label></td>';
    private const REST = '<td class="use-default"><input type="checkbox"/></td><td class=""></td></tr>';

    /**
     * @param string $value The value cell's content
     * @param string $open The value cell's opening tag
     * @return void
     */
    #[DataProvider('valueCells')]
    public function testTheMarkupClosesTheRowsValueCell(string $value, string $open): void
    {
        $row = '<tr id="row_x">' . self::LABEL . $open . $value . '</td>' . self::REST;

        self::assertSame(
            '<tr id="row_x">' . self::LABEL . $open . $value . '<b>button</b></td>' . self::REST,
            (new ValueCell())->append($row, '<b>button</b>')
        );
    }

    /**
     * Value cells by what they hold
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function valueCells(): array
    {
        $plain = '<td class="value">';

        return [
            'a control and a comment' => ['<input/><p class="note"><span>c</span></p>', $plain],
            'a tooltip' => [
                '<input/><div class="tooltip"><span class="help"></span></div>',
                '<td class="value with-tooltip">',
            ],
            'a table of its own' => ['<table><tr><td>a</td><td colspan="2"><td>b</td></td></tr></table>', $plain],
            'cell markup in a script' => [
                '<table><tbody></tbody></table><script>var t = \'<tr><td>\' + x + \'<\/td><td>\';</script>',
                $plain,
            ],
            'cell markup in a comment, a template and a text area' => [
                '<!-- </td> --><template><td></template><textarea></td></textarea>',
                $plain,
            ],
            'upper-case tags and single quotes' => ['<TABLE><TR><TD>a</TD></TR></TABLE>', "<TD CLASS='value'>"],
            'a class list with value among others' => ['<input/>', '<td class="zz value other">'],
        ];
    }

    public function testACellThatIsNotTheValueCellIsLeftAlone(): void
    {
        $cell = new ValueCell();

        self::assertNull($cell->append('<tr><td class="label"></td><td class="values"></td></tr>', '<b/>'));
        self::assertNull($cell->append('<tr><td class="col-value"></td><td data-class="value"></td></tr>', '<b/>'));
        $label = '<tr><td class="label"><table><tr><td class="value"></td></tr></table></td>';
        self::assertSame(
            $label . '<td class="value"><b/></td></tr>',
            $cell->append($label . '<td class="value"></td></tr>', '<b/>'),
            'A value cell nested in another cell is not the row\'s'
        );
    }

    public function testARowWhoseCellsCannotBePairedGetsNothing(): void
    {
        $cell = new ValueCell();

        self::assertNull($cell->append('<tr><td class="value"><table><tr><td>a</tr></table></tr>', '<b/>'));
        self::assertNull($cell->append('</td><td class="value"></td>', '<b/>'));
        self::assertNull($cell->append('', '<b/>'));
    }
}
