<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Model\AdminRequest\ParameterSanitizer;
use PHPUnit\Framework\TestCase;

class ParameterSanitizerTest extends TestCase
{
    public function testFlattensNamesTheWayAFormPostsThemAndMasksSecretsAtAnyDepth(): void
    {
        $result = $this->sanitizer()->sanitize([
            'product' => ['name' => 'Chaz Kangaroo Hoodie', 'stock_data' => ['qty' => 100], 'status' => true],
            'groups' => ['ups' => ['fields' => ['password' => ['value' => 'hunter2']]]],
            'form_key' => 'aBcD1234',
            'key' => '0f9e8d7c',
            'back' => null,
        ]);

        self::assertSame([
            ['name' => 'product[name]', 'value' => 'Chaz Kangaroo Hoodie', 'tag' => ''],
            ['name' => 'product[stock_data][qty]', 'value' => '100', 'tag' => ''],
            ['name' => 'product[status]', 'value' => '1', 'tag' => ''],
            ['name' => 'groups[ups][fields][password][value]', 'value' => SanitizerInterface::MASK, 'tag' => 'masked'],
            ['name' => 'form_key', 'value' => SanitizerInterface::MASK, 'tag' => 'masked'],
            ['name' => 'key', 'value' => SanitizerInterface::MASK, 'tag' => 'masked'],
            ['name' => 'back', 'value' => '', 'tag' => ''],
        ], $result['parameters']);
        self::assertSame(0, $result['omitted']);
    }

    public function testCutsLongValuesAndCountsWhatIsLeftOut(): void
    {
        $result = $this->sanitizer(10, 2)
            ->sanitize(['a' => str_repeat('é', 12), 'b' => 'short', 'c' => 'x', 'd' => 'y']);

        self::assertSame(
            [
                ['name' => 'a', 'value' => str_repeat('é', 10) . '…', 'tag' => 'truncated'],
                ['name' => 'b', 'value' => 'short', 'tag' => ''],
            ],
            $result['parameters']
        );
        self::assertSame(2, $result['omitted']);
    }

    /**
     * @param int $maxValueLength
     * @param int $maxParameters
     * @return ParameterSanitizer
     */
    private function sanitizer(int $maxValueLength = 200, int $maxParameters = 300): ParameterSanitizer
    {
        $sanitizer = $this->createStub(SanitizerInterface::class);
        $sanitizer->method('isSecretField')->willReturnCallback(
            static fn (string $field): bool => str_contains(strtolower($field), 'password')
        );

        return new ParameterSanitizer($sanitizer, $maxValueLength, $maxParameters);
    }
}
