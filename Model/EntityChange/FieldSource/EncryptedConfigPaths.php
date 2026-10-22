<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource;

use Hryvinskyi\AdminActionLog\Api\SecretFieldsInterface;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Configuration paths whose values the configuration keeps encrypted, whatever the path is called
 *
 * A path is encrypted when the setting it declares in the admin's configuration structure (following config_path
 * declarations) saves its value through an encrypting backend model. The structure is the admin's in every area, so a
 * change recorded from a console command or a queue consumer is masked as one saved in the admin is.
 */
class EncryptedConfigPaths implements SecretFieldsInterface, ResetAfterRequestInterface
{
    private const BACKEND_MODEL = 'backend_model';

    /**
     * Whether each path asked about is kept encrypted
     *
     * @var array<string, bool>
     */
    private array $encrypted = [];

    /**
     * @param Structure $structure The admin's configuration structure
     */
    public function __construct(
        private readonly Structure $structure
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isSecretField(string $field): bool
    {
        return $this->encrypted[$field] ??= $this->isEncrypted($field);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->encrypted = [];
    }

    /**
     * Whether the setting the path declares saves its value through an encrypting backend model
     *
     * @param string $path
     * @return bool
     */
    private function isEncrypted(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $field = $this->structure->getElementByConfigPath($path);
        $backendModel = $field instanceof Field ? $field->getAttribute(self::BACKEND_MODEL) : null;
        $class = is_string($backendModel) ? ltrim($backendModel, '\\') : '';

        return $class !== '' && is_a($class, Encrypted::class, true);
    }
}
