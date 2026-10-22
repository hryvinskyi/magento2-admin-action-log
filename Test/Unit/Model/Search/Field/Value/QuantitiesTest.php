<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Field\Value;

use Hryvinskyi\AdminActionLog\Model\Search\Field\Value\Quantities;
use PHPUnit\Framework\TestCase;

/**
 * Quantities as the stock forms write them, without the zeros the table pads them with
 */
class QuantitiesTest extends TestCase
{
    public function testAStoredDecimalLosesItsTrailingZeros(): void
    {
        self::assertSame(
            ['100.0000' => '100', '4.5000' => '4.5', '0.1250' => '0.125', '-2.0000' => '-2', '95' => '95'],
            (new Quantities())->namesOf(['100.0000', '4.5000', '0.1250', '-2.0000', '95'])
        );
    }

    public function testTextThatIsNotADecimalIsShownAsStored(): void
    {
        self::assertSame([], (new Quantities())->namesOf(['many', '1e3', '']));
    }
}
