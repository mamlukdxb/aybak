<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\NodeSet;
use PHPUnit\Framework\TestCase;

final class NodeSetTest extends TestCase
{
    public function testWorkersByInternalIpLeaveTheControlPlaneOut(): void
    {
        $set = NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'InternalIP');

        self::assertSame([
            ['name' => 'w-bursa', 'address' => '5.223.52.186'],
            ['name' => 'w-islambul', 'address' => '5.223.53.18'],
            ['name' => 'w-yenisehir', 'address' => '5.223.43.147'],
        ], $set->nodes);
    }

    /** beyazid's and cagri's gateways use the private addresses only Calico records. */
    public function testCalicoGivesThePrivateAddressWithoutItsPrefixLength(): void
    {
        $set = NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'calico');

        self::assertSame(['10.1.0.4', '10.1.0.6', '10.1.0.5'], array_column($set->nodes, 'address'));
    }

    public function testAllIncludesTheControlPlane(): void
    {
        $set = NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'all', 'InternalIP');

        self::assertSame(['m1', 'w-bursa', 'w-islambul', 'w-yenisehir'], array_column($set->nodes, 'name'));
    }

    public function testANodeNotReadyOrCordonedOrWithoutTheAddressIsLeftOut(): void
    {
        $set = NodeSet::fromNodeList('c', [
            Fixtures::node('a', '10.0.0.1'),
            Fixtures::node('b', '10.0.0.2', ready: false),
            Fixtures::node('c', '10.0.0.3', cordoned: true),
            Fixtures::node('d', '10.0.0.4', external: '203.0.113.4'),
        ], 'workers', 'ExternalIP');

        self::assertSame([['name' => 'd', 'address' => '203.0.113.4']], $set->nodes);
    }

    public function testANodeWithNoReadyConditionIsNotReady(): void
    {
        $node = Fixtures::node('a', '10.0.0.1');
        $node['status']['conditions'] = [];

        self::assertTrue(NodeSet::fromNodeList('c', [$node], 'workers', 'InternalIP')->isEmpty());
    }

    public function testAnAddressThatIsNotAnIpIsLeftOut(): void
    {
        self::assertTrue(NodeSet::fromNodeList('c', [Fixtures::node('a', 'not-an-ip')], 'workers', 'InternalIP')->isEmpty());
        self::assertTrue(NodeSet::fromNodeList('c', [Fixtures::node('a', '10.0.0.1', 'garbage/32')], 'workers', 'calico')->isEmpty());
    }

    public function testTheOrderOfTheListDoesNotChangeTheFingerprint(): void
    {
        $a = NodeSet::fromNodeList('c', Fixtures::beyazid(), 'workers', 'calico');
        $b = NodeSet::fromNodeList('c', array_reverse(Fixtures::beyazid()), 'workers', 'calico');

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testTheArrayCarriesClusterTimeAndNodes(): void
    {
        $set = NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'calico');
        $data = $set->toArray(new \DateTimeImmutable('2026-11-11T12:00:00+04:00'));

        self::assertSame('beyazid', $data['cluster']);
        self::assertSame('2026-11-11T08:00:00Z', $data['generated']);
        self::assertCount(3, $data['nodes']);
    }
}
