<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

/**
 * Standalone bootstrap for this module's unit tests.
 *
 * The classes under test are exercised against Composer's autoloader, without booting Magento.
 */

$autoloadCandidates = [
    __DIR__ . '/../../../../autoload.php', // vendor/<v>/<m>/Test/Unit -> vendor/autoload.php
    __DIR__ . '/../../vendor/autoload.php', // module run standalone
    __DIR__ . '/../../../../../../vendor/autoload.php', // app/code/<V>/<M>/Test/Unit -> root vendor
];

$loader = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $loader = require $candidate;
        break;
    }
}

if (!$loader instanceof \Composer\Autoload\ClassLoader) {
    throw new \RuntimeException('Unable to locate Composer autoloader for the test bootstrap.');
}

$loader->addPsr4('Hryvinskyi\\AdminActionLog\\', dirname(__DIR__, 2));
$loader->addPsr4('Hryvinskyi\\AdminActionLog\\Test\\Unit\\', __DIR__);

// When running inside a Magento installation, also resolve generated factories
// (e.g. Magento\Framework\HTTP\Client\CurlFactory) from generated/code.
foreach ($autoloadCandidates as $candidate) {
    $generated = dirname($candidate, 2) . '/generated/code';

    if (is_dir($generated)) {
        $loader->add('', [$generated]);
        break;
    }
}

if (!function_exists('__')) {
    /**
     * Minimal stand-in for Magento's translate function so classes that build
     * \Magento\Framework\Phrase messages can run without booting Magento.
     *
     * @param mixed ...$argc
     * @return \Magento\Framework\Phrase
     */
    function __(...$argc) // phpcs:ignore
    {
        $text = array_shift($argc);

        if (!empty($argc) && is_array($argc[0])) {
            $argc = $argc[0];
        }

        return new \Magento\Framework\Phrase(is_string($text) ? $text : '', $argc);
    }
}
