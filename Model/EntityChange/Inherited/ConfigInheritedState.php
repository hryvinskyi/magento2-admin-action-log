<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Inherited;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * The value a configuration setting had in its scope before its first row there was saved
 *
 * The configuration loaded for the request still answers as it did before the save: for a scope without a row, with
 * the wider scope's value or the module's default. A path that names a group of settings rather than one inherits
 * nothing.
 */
class ConfigInheritedState implements InheritedStateInterface
{
    private const PATH = 'path';
    private const VALUE = 'value';
    private const SCOPE = 'scope';
    private const SCOPE_ID = 'scope_id';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function stateOf(AbstractModel $model): ?array
    {
        $path = $model->getData(self::PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $scope = $model->getData(self::SCOPE);
        $scope = is_string($scope) && $scope !== '' ? $scope : ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeId = $model->getData(self::SCOPE_ID);
        $value = $this->scopeConfig->getValue(
            $path,
            $scope,
            $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT || !is_numeric($scopeId) ? null : (int) $scopeId
        );
        if ($value !== null && !is_scalar($value)) {
            return null;
        }

        return [self::PATH => $path, self::VALUE => $value];
    }
}
