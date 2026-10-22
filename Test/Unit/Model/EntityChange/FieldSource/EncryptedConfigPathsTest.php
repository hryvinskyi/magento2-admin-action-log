<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\FieldSource;

use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource\EncryptedConfigPaths;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Framework\App\Config\Value;
use PHPUnit\Framework\TestCase;

/**
 * A path is secret when the setting it declares saves through an encrypting backend model, whatever it is called
 */
class EncryptedConfigPathsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $lookups = [];

    public function testAPathSavedThroughAnEncryptingBackendModelIsSecret(): void
    {
        $paths = $this->paths();

        self::assertTrue($paths->isSecretField('carriers/courier/account'));
        self::assertTrue($paths->isSecretField('carriers/courier/key'), 'A backend model extending the encrypting one');
        self::assertFalse($paths->isSecretField('carriers/courier/title'), 'Another backend model');
        self::assertFalse($paths->isSecretField('carriers/courier/active'), 'No backend model');
        self::assertFalse($paths->isSecretField('carriers/courier'), 'Not a setting');
        self::assertFalse($paths->isSecretField(''));
    }

    public function testEachPathIsLookedUpOncePerRequest(): void
    {
        $paths = $this->paths();
        $paths->isSecretField('carriers/courier/account');
        $paths->isSecretField('carriers/courier/account');
        $paths->_resetState();
        $paths->isSecretField('carriers/courier/account');

        self::assertSame(['carriers/courier/account', 'carriers/courier/account'], $this->lookups);
    }

    private function paths(): EncryptedConfigPaths
    {
        $backendModels = [
            'carriers/courier/account' => '\\' . Encrypted::class,
            'carriers/courier/key' => SubclassOfEncrypted::class,
            'carriers/courier/title' => Value::class,
            'carriers/courier/active' => null,
        ];
        $structure = $this->createMock(Structure::class);
        $structure->method('getElementByConfigPath')->willReturnCallback(
            function (string $path) use ($backendModels): Field|Group {
                $this->lookups[] = $path;
                if (!array_key_exists($path, $backendModels)) {
                    return $this->createStub(Group::class);
                }

                $field = $this->createStub(Field::class);
                $field->method('getAttribute')->willReturnCallback(
                    static fn (string $key): ?string => $key === 'backend_model' ? $backendModels[$path] : null
                );

                return $field;
            }
        );

        return new EncryptedConfigPaths($structure);
    }
}
