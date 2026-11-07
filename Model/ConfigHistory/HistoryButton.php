<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

use Hryvinskyi\ActivityLog\Api\IconsInterface;
use Magento\Framework\Escaper;

/**
 * The markup of a setting's history button, printed right after the setting's control
 *
 * A clock button carrying what the page's script needs as data attributes: the setting's config path and label, its
 * number of recorded changes and the time and actor of the newest (empty when it never changed). A setting changed
 * in the last days gets a dot; one never changed is drawn muted. Every value is escaped; the script fills the
 * button's title and opens the history from these attributes alone.
 *
 * @phpstan-import-type SettingHistory from SectionHistory
 */
class HistoryButton
{
    /**
     * The value of data-role that marks a history button
     */
    public const ROLE = 'config-history-open';

    /**
     * @param Escaper $escaper
     * @param IconsInterface $icons
     */
    public function __construct(
        private readonly Escaper $escaper,
        private readonly IconsInterface $icons
    ) {
    }

    /**
     * The button of one setting
     *
     * @param string $path The setting's config path
     * @param string $label The setting's label as its row shows it
     * @param SettingHistory|null $history Null when no change of the setting was recorded
     * @return string
     */
    public function html(string $path, string $label, ?array $history): string
    {
        $state = $history === null ? 'never' : ($history['recent'] ? 'recent' : 'changed');
        $attributes = [
            'type' => 'button',
            'class' => 'config-history__button config-history__button--' . $state,
            'data-role' => self::ROLE,
            'data-path' => $path,
            'data-label' => $label,
            'data-changes' => (string) ($history['changes'] ?? 0),
            'data-latest-ms' => (string) ($history['latestMs'] ?? ''),
            'data-latest-actor' => $history['latestActor'] ?? '',
            'aria-haspopup' => 'dialog',
            'aria-expanded' => 'false',
            'aria-label' => (string) __('History of %1', $label),
        ];

        $html = '<span class="config-history"><button';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . $this->escaper->escapeHtmlAttr($value) . '"';
        }
        $html .= '>' . $this->icons->render('Hryvinskyi_ActivityLog::clock', 'config-history__icon');
        if ($state === 'recent') {
            $html .= '<span class="config-history__dot" aria-hidden="true"></span>';
        }

        return $html . '</button></span>';
    }
}
