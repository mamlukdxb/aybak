<?php

declare(strict_types=1);

namespace Aybak\Output;

use Aybak\Http\CurlTransport;
use Aybak\Http\Transport;
use Aybak\NodeSet;

/**
 * POSTs the node set as JSON. The header X-Aybak-Signature carries
 * `sha256=<hex>`, the HMAC-SHA256 of the exact body with the shared key, so
 * the receiver can reject a set it did not get from this aybak.
 */
final readonly class Webhook implements Output
{
    public function __construct(private Transport $http, private string $url, private string $keyFile, private ?string $caFile = null)
    {
    }

    public function name(): string
    {
        return 'webhook';
    }

    public function send(NodeSet $set, \DateTimeImmutable $at): void
    {
        $key = @file_get_contents($this->keyFile);
        if ($key === false || trim($key) === '') {
            throw new \RuntimeException("The webhook key at {$this->keyFile} could not be read.");
        }
        $body = json_encode($set->toArray($at), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->http->request('POST', $this->url, [
            'Content-Type' => 'application/json',
            'X-Aybak-Signature' => 'sha256=' . self::sign($body, trim($key)),
        ], $body, $this->caFile !== null ? ['ca_file' => $this->caFile] : []);
        if (!$response->ok()) {
            throw new \RuntimeException('The webhook at ' . CurlTransport::redact($this->url) . " answered HTTP {$response->status}.");
        }
    }

    public static function sign(string $body, string $key): string
    {
        return hash_hmac('sha256', $body, $key);
    }
}
