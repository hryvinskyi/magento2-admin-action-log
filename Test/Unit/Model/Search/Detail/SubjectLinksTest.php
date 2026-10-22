<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Detail;

use Hryvinskyi\AdminActionLog\Model\Search\Detail\EditPage;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\SubjectLinks;
use Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Detail\Fake\RecordedPresence;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Links to the records of a page of changes: only to records that exist, of kinds the admin may open
 */
class SubjectLinksTest extends TestCase
{
    /**
     * The record source of each kind
     *
     * @var array<string, RecordedPresence>
     */
    private array $records = [];

    public function testLinksEachExistingRecordAfterOneQueryPerKind(): void
    {
        $links = $this->links(
            ['product' => ['5', '7'], 'cms_block' => ['3']],
            ['Magento_Catalog::products', 'Magento_Cms::block']
        );

        $result = $links->linksFor([
            101 => ['type' => 'product', 'id' => '5'],
            102 => ['type' => 'product', 'id' => '6'],
            103 => ['type' => 'product', 'id' => '5'],
            104 => ['type' => 'cms_block', 'id' => '3'],
        ]);

        self::assertSame([
            101 => '/admin/catalog/product/edit?id=5',
            103 => '/admin/catalog/product/edit?id=5',
            104 => '/admin/cms/block/edit?block_id=3',
        ], $result);
        self::assertSame(['product' => [['5', '6']], 'cms_block' => [['3']]], $this->asked());
    }

    public function testLeavesOutKindsTheAdminMayNotOpenWithoutAskingWhetherTheyExist(): void
    {
        $links = $this->links(['product' => ['5'], 'cms_block' => ['3']], ['Magento_Cms::block']);

        $result = $links->linksFor([
            101 => ['type' => 'product', 'id' => '5'],
            104 => ['type' => 'cms_block', 'id' => '3'],
        ]);

        self::assertSame([104 => '/admin/cms/block/edit?block_id=3'], $result);
        self::assertArrayNotHasKey('product', $this->asked());
    }

    public function testLeavesOutKindsWithoutAnEditPageAndRecordsWithoutAnId(): void
    {
        $links = $this->links(['product' => ['5']], ['Magento_Catalog::products']);

        self::assertSame([], $links->linksFor([
            201 => ['type' => 'config_value', 'id' => '12'],
            202 => ['type' => 'product', 'id' => null],
            203 => ['type' => 'product', 'id' => ''],
        ]));
        self::assertSame([], $this->asked());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function malformedPages(): array
    {
        return [
            'a route without an action' => ['catalog/product', 'id', 'Magento_Catalog::products'],
            'a route with a query' => ['catalog/product/edit?x=1', 'id', 'Magento_Catalog::products'],
            'an id parameter with a slash' => ['catalog/product/edit', 'id/5', 'Magento_Catalog::products'],
            'an ACL resource without a module' => ['catalog/product/edit', 'id', 'products'],
        ];
    }

    #[DataProvider('malformedPages')]
    public function testRefusesAMalformedEditPage(string $route, string $idParam, string $aclResource): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EditPage(new RecordedPresence([]), $route, $idParam, $aclResource);
    }

    /**
     * Links over two kinds of record, of which the given ids exist, for an admin allowed the given resources
     *
     * @param array<string, list<string>> $existing
     * @param list<string> $allowed
     * @return SubjectLinks
     */
    private function links(array $existing, array $allowed): SubjectLinks
    {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (mixed $route, mixed $params): string {
                self::assertIsString($route);
                self::assertIsArray($params);

                return '/admin/' . $route . '?' . http_build_query($params);
            }
        );

        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (mixed $resource): bool => in_array($resource, $allowed, true)
        );

        return new SubjectLinks($url, $authorization, [
            'product' => new EditPage(
                $this->records('product', $existing['product'] ?? []),
                'catalog/product/edit',
                'id',
                'Magento_Catalog::products'
            ),
            'cms_block' => new EditPage(
                $this->records('cms_block', $existing['cms_block'] ?? []),
                'cms/block/edit',
                'block_id',
                'Magento_Cms::block'
            ),
        ]);
    }

    /**
     * A record source of which the given ids exist, kept to tell what it was asked
     *
     * @param string $kind
     * @param list<string> $existing
     * @return RecordedPresence
     */
    private function records(string $kind, array $existing): RecordedPresence
    {
        $this->records[$kind] = new RecordedPresence($existing);

        return $this->records[$kind];
    }

    /**
     * The ids each kind's source was asked about, one entry per query; kinds never asked are left out
     *
     * @return array<string, list<list<string>>>
     */
    private function asked(): array
    {
        return array_filter(array_map(
            static fn (RecordedPresence $records): array => $records->calls,
            $this->records
        ));
    }
}
