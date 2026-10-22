<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource;

use Hryvinskyi\AdminActionLog\Api\SecretFieldsInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\EntityStorageInterface;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Model\AbstractModel;

/**
 * A configuration value is one field named by its path: "web/secure/base_url"
 *
 * A value kept encrypted is a secret whatever its path is called: it is saved through an encrypting backend model, or
 * its path declares a setting the configuration keeps encrypted ("encryptedPaths", the rule changes recorded without
 * a model are masked by too).
 */
class ConfigValueFieldSource implements FieldSourceInterface
{
    private const PATH = 'path';
    private const VALUE = 'value';

    /**
     * @param SecretFieldsInterface $encryptedPaths
     */
    public function __construct(
        private readonly SecretFieldsInterface $encryptedPaths
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(EntityStorageInterface $storage, AbstractModel $model, array $data): array
    {
        $path = $data[self::PATH] ?? $model->getData(self::PATH);
        if (!is_string($path) || $path === '') {
            return [];
        }

        return [$path => $data[self::VALUE] ?? null];
    }

    /**
     * @inheritDoc
     */
    public function isSecret(AbstractModel $model, string $field): bool
    {
        return $model instanceof Encrypted || $this->encryptedPaths->isSecretField($field);
    }
}
