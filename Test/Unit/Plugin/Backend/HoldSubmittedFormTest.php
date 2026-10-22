<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Plugin\Backend;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\SubmittedForm;
use Hryvinskyi\AdminActionLog\Plugin\Backend\HoldSubmittedForm;
use Magento\Backend\App\AbstractAction;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * What an admin submitted is held while the action runs, and only then
 */
class HoldSubmittedFormTest extends TestCase
{
    public function testTheParametersAreHeldWhileTheActionRunsAndDroppedAfter(): void
    {
        $form = new SubmittedForm();
        $seen = null;

        $result = (new HoldSubmittedForm($form))->aroundDispatch(
            $this->createMock(AbstractAction::class),
            static function () use ($form, &$seen): string {
                $seen = $form->parameters();

                return 'response';
            },
            $this->request(['id' => '335', 'product' => ['price' => '39.00']])
        );

        self::assertSame('response', $result, 'The action\'s result passes through');
        self::assertSame(['id' => '335', 'product' => ['price' => '39.00']], $seen);
        self::assertNull($form->parameters());
    }

    public function testTheParametersAreDroppedWhenTheActionThrows(): void
    {
        $form = new SubmittedForm();
        $failure = new \RuntimeException('The product could not be saved.');

        try {
            (new HoldSubmittedForm($form))->aroundDispatch(
                $this->createMock(AbstractAction::class),
                static function () use ($failure): never {
                    throw $failure;
                },
                $this->request(['id' => '335'])
            );
            self::fail('The action\'s error must reach the caller');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertNull($form->parameters());
    }

    /**
     * @param array<string, mixed> $parameters
     * @return RequestInterface
     */
    private function request(array $parameters): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParams')->willReturn($parameters);

        return $request;
    }
}
