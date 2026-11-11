<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\Http\Response;
use Aybak\Http\Transport;

/** Node objects as the API returns them, reduced to the fields aybak reads. */
final class Fixtures
{
    /**
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    public static function node(
        string $name,
        string $internal,
        ?string $calico = null,
        bool $ready = true,
        bool $cordoned = false,
        array $labels = [],
        ?string $external = null,
    ): array {
        $addresses = [['type' => 'InternalIP', 'address' => $internal], ['type' => 'Hostname', 'address' => $name]];
        if ($external !== null) {
            $addresses[] = ['type' => 'ExternalIP', 'address' => $external];
        }

        return [
            'metadata' => [
                'name' => $name,
                'labels' => $labels,
                'annotations' => $calico === null ? [] : ['projectcalico.org/IPv4Address' => $calico],
            ],
            'spec' => $cordoned ? ['unschedulable' => true] : [],
            'status' => [
                'addresses' => $addresses,
                'conditions' => [['type' => 'MemoryPressure', 'status' => 'False'], ['type' => 'Ready', 'status' => $ready ? 'True' : 'False']],
            ],
        ];
    }

    /** @return list<array<string, mixed>> beyazid's shape on 2026-11-11 */
    public static function beyazid(): array
    {
        return [
            self::node('m1', '5.223.44.168', '10.1.0.2/32', labels: ['node-role.kubernetes.io/control-plane' => '']),
            self::node('w-bursa', '5.223.52.186', '10.1.0.4/32'),
            self::node('w-islambul', '5.223.53.18', '10.1.0.6/32'),
            self::node('w-yenisehir', '5.223.43.147', '10.1.0.5/32'),
        ];
    }
}

/** Records every request and answers from a queue, or 200 with an empty object. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<Response> */
    public array $answers = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'options');

        return array_shift($this->answers) ?? new Response(200, '{}');
    }
}
