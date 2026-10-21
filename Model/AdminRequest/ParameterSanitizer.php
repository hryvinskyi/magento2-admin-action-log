<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;

/**
 * Turns request parameters into a flat list that is safe to store
 *
 * Nested names are flattened the way a form posts them ("product[stock_data][qty]"). A value is masked when any part
 * of its name is a secret field, or is the form key or the admin URL key; a value longer than the limit is cut and
 * tagged. Parameters past the count limit are left out and only counted.
 */
class ParameterSanitizer
{
    public const TAG_MASKED = 'masked';
    public const TAG_TRUNCATED = 'truncated';
    private const ELLIPSIS = '…';

    /**
     * @param SanitizerInterface $sanitizer
     * @param int $maxValueLength
     * @param int $maxParameters
     * @param list<string> $alwaysMasked Parameter names masked whatever the secret list says, lowercase
     */
    public function __construct(
        private readonly SanitizerInterface $sanitizer,
        private readonly int $maxValueLength = 200,
        private readonly int $maxParameters = 300,
        private readonly array $alwaysMasked = ['key', 'form_key']
    ) {
    }

    /**
     * Flat, masked and cut parameters with the count of those left out
     *
     * @param array<mixed> $parameters
     * @return array{parameters: list<array{name: string, value: string, tag: string}>, omitted: int}
     */
    public function sanitize(array $parameters): array
    {
        $flat = [];
        $omitted = 0;
        $this->flatten($parameters, '', false, $flat, $omitted);

        return ['parameters' => $flat, 'omitted' => $omitted];
    }

    /**
     * Add the values to the flat list under form-style names
     *
     * @param array<mixed> $values
     * @param string $prefix
     * @param bool $secret Whether a parent name is already secret
     * @param list<array{name:string,value:string,tag:string}> $flat
     * @param int $omitted
     * @return void
     */
    private function flatten(array $values, string $prefix, bool $secret, array &$flat, int &$omitted): void
    {
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $name = $prefix === '' ? $key : $prefix . '[' . $key . ']';
            $isSecret = $secret || $this->isSecret($key);

            if (is_array($value)) {
                $this->flatten($value, $name, $isSecret, $flat, $omitted);
                continue;
            }

            if (count($flat) >= $this->maxParameters) {
                $omitted++;
                continue;
            }

            $flat[] = $isSecret
                ? ['name' => $name, 'value' => SanitizerInterface::MASK, 'tag' => self::TAG_MASKED]
                : $this->entry($name, $this->text($value));
        }
    }

    /**
     * One parameter, cut and tagged when too long
     *
     * @param string $name
     * @param string $value
     * @return array{name: string, value: string, tag: string}
     */
    private function entry(string $name, string $value): array
    {
        if (mb_strlen($value) <= $this->maxValueLength) {
            return ['name' => $name, 'value' => $value, 'tag' => ''];
        }

        return [
            'name' => $name,
            'value' => mb_substr($value, 0, $this->maxValueLength) . self::ELLIPSIS,
            'tag' => self::TAG_TRUNCATED,
        ];
    }

    /**
     * Whether a name part marks a secret
     *
     * @param string $key
     * @return bool
     */
    private function isSecret(string $key): bool
    {
        return in_array(strtolower($key), $this->alwaysMasked, true) || $this->sanitizer->isSecretField($key);
    }

    /**
     * A parameter value as text
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            $value === null => '',
            default => '[' . get_debug_type($value) . ']',
        };
    }
}
