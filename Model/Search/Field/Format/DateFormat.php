<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Format;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * A date attribute's value in the admin's locale: the date alone ("Oct 5, 2026"), or with its time for an attribute
 * that keeps one
 *
 * A date alone is a day of the calendar and is written as stored, never moved by a timezone; a date with a time is
 * stored in UTC and written in the store's timezone, as the admin's forms show it. Text that is not a date is left as
 * stored. Configure one per input type: "inputType" names the frontend input it applies to, "withTime" whether the
 * time is written.
 */
class DateFormat implements AttributeValueFormatInterface
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/D';
    private const STORED_TIMEZONE = 'UTC';

    /**
     * @param TimezoneInterface $timezone
     * @param string $inputType Frontend input of the attributes it writes: "date", "datetime"
     * @param bool $withTime Whether the time is written after the date
     */
    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly string $inputType = 'date',
        private readonly bool $withTime = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(AbstractAttribute $attribute): bool
    {
        return $attribute->getFrontendInput() === $this->inputType;
    }

    /**
     * @inheritDoc
     */
    public function format(AbstractAttribute $attribute, array $values): array
    {
        $shown = [];
        foreach ($values as $value) {
            $written = preg_match(self::DATE_PATTERN, $value) === 1 ? $this->written($value) : null;
            if ($written !== null) {
                $shown[$value] = $written;
            }
        }

        return $shown;
    }

    /**
     * A stored date as the admin reads it, or null when it is no date
     *
     * @param string $value
     * @return string|null
     */
    private function written(string $value): ?string
    {
        try {
            $written = $this->timezone->formatDateTime(
                $value,
                \IntlDateFormatter::MEDIUM,
                $this->withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
                null,
                $this->withTime ? null : self::STORED_TIMEZONE
            );
        } catch (\Exception) {
            return null;
        }

        return $written === '' ? null : $written;
    }
}
