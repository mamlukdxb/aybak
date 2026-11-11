<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\Http\Response;
use Aybak\Kubernetes;
use Aybak\NodeSet;
use Aybak\Output\DataPlane;
use Aybak\Output\Webhook;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aybak-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/key", "webhook-shared-key\n");
        file_put_contents("{$this->dir}/password", "dataplane-password\n");
        file_put_contents("{$this->dir}/token", "sa-token\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    private function set(): NodeSet
    {
        return NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'calico');
    }

    public function testTheWebhookBodyIsSignedWithTheSharedKey(): void
    {
        $http = new FakeTransport();
        (new Webhook($http, 'https://hooks.example.org/aybak', "{$this->dir}/key"))->send($this->set(), new \DateTimeImmutable());

        $r = $http->requests[0];
        self::assertSame('POST', $r['method']);
        self::assertSame('sha256=' . hash_hmac('sha256', (string) $r['body'], 'webhook-shared-key'), $r['headers']['X-Aybak-Signature']);
        self::assertSame(['10.1.0.4', '10.1.0.6', '10.1.0.5'], array_column(json_decode((string) $r['body'], true)['nodes'], 'address'));
    }

    public function testAWebhookThatRefusesIsAFailure(): void
    {
        $http = new FakeTransport();
        $http->answers = [new Response(500, '')];

        $this->expectExceptionMessage('answered HTTP 500');
        (new Webhook($http, 'https://hooks.example.org/aybak', "{$this->dir}/key"))->send($this->set(), new \DateTimeImmutable());
    }

    public function testTheDataPlaneReplacesEveryServerInOneTransaction(): void
    {
        $http = new FakeTransport();
        $http->answers = [
            new Response(200, '7'),                                   // version
            new Response(201, '{"id":"t1"}'),                          // transaction
            new Response(200, '[{"name":"old-a"},{"name":"old-b"}]'),  // http_back servers
            new Response(204, ''), new Response(204, ''),              // two deletes
            new Response(201, '{}'), new Response(201, '{}'), new Response(201, '{}'),
            new Response(200, '[]'),                                    // https_back servers
            new Response(201, '{}'), new Response(201, '{}'), new Response(201, '{}'),
            new Response(202, '{}'),                                    // commit
        ];
        (new DataPlane($http, 'https://lb:5555', 'aybak', "{$this->dir}/password", ['http_back' => 32080, 'https_back' => 32443], true))
            ->send($this->set(), new \DateTimeImmutable());

        $calls = array_map(static fn (array $r): string => $r['method'] . ' ' . strtok($r['url'], '?'), $http->requests);
        self::assertSame('GET https://lb:5555/v3/services/haproxy/configuration/version', $calls[0]);
        self::assertSame('POST https://lb:5555/v3/services/haproxy/transactions', $calls[1]);
        self::assertSame('DELETE https://lb:5555/v3/services/haproxy/configuration/backends/http_back/servers/old-a', $calls[3]);
        self::assertSame('PUT https://lb:5555/v3/services/haproxy/transactions/t1', end($calls));
        self::assertCount(13, $calls);
        foreach (array_slice($http->requests, 2, -1) as $r) {
            self::assertStringContainsString('transaction_id=t1', $r['url']);
        }
        $server = json_decode((string) $http->requests[5]['body'], true);
        self::assertSame(['name' => 'aybak-w-bursa', 'address' => '10.1.0.4', 'port' => 32080, 'check' => 'enabled', 'send-proxy-v2' => 'enabled'], $server);
        self::assertSame(['aybak', 'dataplane-password'], $http->requests[0]['options']['basic_auth']);
    }

    public function testAFailureInsideTheTransactionDeletesItAndCommitsNothing(): void
    {
        $http = new FakeTransport();
        $http->answers = [new Response(200, '7'), new Response(201, '{"id":"t1"}'), new Response(200, '[]'), new Response(400, '{}'), new Response(204, '')];

        try {
            (new DataPlane($http, 'https://lb:5555', 'aybak', "{$this->dir}/password", ['http_back' => 32080], false))
                ->send($this->set(), new \DateTimeImmutable());
            self::fail('A refused server must fail the send.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('answered HTTP 400', $e->getMessage());
        }
        $last = end($http->requests);
        self::assertSame('DELETE', $last['method']);
        self::assertSame('https://lb:5555/v3/services/haproxy/transactions/t1', $last['url']);
        self::assertNotContains('PUT', array_column($http->requests, 'method'));
    }

    public function testKubernetesSendsTheTokenAndRejectsAnythingButANodeList(): void
    {
        $http = new FakeTransport();
        $http->answers = [new Response(200, json_encode(['kind' => 'NodeList', 'items' => Fixtures::beyazid()])), new Response(200, '{"kind":"Status"}')];
        $k = new Kubernetes($http, 'https://10.96.0.1:443', "{$this->dir}/token", '/ca.crt');

        self::assertCount(4, $k->nodes());
        self::assertSame('Bearer sa-token', $http->requests[0]['headers']['Authorization']);
        self::assertSame('/ca.crt', $http->requests[0]['options']['ca_file']);

        $this->expectExceptionMessage('did not answer a NodeList');
        $k->nodes();
    }
}
