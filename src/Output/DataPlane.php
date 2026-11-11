<?php

declare(strict_types=1);

namespace Aybak\Output;

use Aybak\Http\CurlTransport;
use Aybak\Http\Response;
use Aybak\Http\Transport;
use Aybak\NodeSet;

/**
 * Replaces the servers of named backends through HAProxy's Data Plane API
 * (v3), in one transaction for all of them: HAProxy sees either the old
 * servers everywhere or the new ones everywhere. A failure at any step
 * deletes the transaction, so nothing half-applied is ever committed.
 *
 * Every server in a named backend is replaced, including any added by hand:
 * a backend aybak feeds belongs to aybak.
 */
final readonly class DataPlane implements Output
{
    /**
     * @param array<string, int> $backends backend name => server port
     */
    public function __construct(
        private Transport $http,
        private string $url,
        private string $user,
        private string $passwordFile,
        private array $backends,
        private bool $sendProxyV2,
        private ?string $caFile = null,
    ) {
    }

    public function name(): string
    {
        return 'dataplane';
    }

    public function send(NodeSet $set, \DateTimeImmutable $at): void
    {
        $password = @file_get_contents($this->passwordFile);
        if ($password === false || trim($password) === '') {
            throw new \RuntimeException("The Data Plane API password at {$this->passwordFile} could not be read.");
        }
        $auth = [$this->user, trim($password)];
        $base = rtrim($this->url, '/') . '/v3/services/haproxy';

        // The version is answered as a bare JSON number, not an object.
        $version = json_decode($this->call('GET', "$base/configuration/version", $auth)->body, false, 2, JSON_THROW_ON_ERROR);
        if (!is_int($version)) {
            throw new \RuntimeException('The Data Plane API answered no configuration version.');
        }
        $transaction = (string) ($this->call('POST', "$base/transactions?version=$version", $auth)->json()['id'] ?? '');
        if ($transaction === '') {
            throw new \RuntimeException('The Data Plane API opened no transaction.');
        }

        try {
            foreach ($this->backends as $backend => $port) {
                $servers = "$base/configuration/backends/" . rawurlencode($backend) . '/servers';
                foreach ($this->call('GET', "$servers?transaction_id=$transaction", $auth)->json() as $existing) {
                    $this->call('DELETE', "$servers/" . rawurlencode((string) $existing['name']) . "?transaction_id=$transaction", $auth);
                }
                foreach ($set->nodes as $node) {
                    $server = [
                        'name' => self::serverName($node['name']),
                        'address' => $node['address'],
                        'port' => $port,
                        'check' => 'enabled',
                    ];
                    if ($this->sendProxyV2) {
                        $server['send-proxy-v2'] = 'enabled';
                    }
                    $this->call('POST', "$servers?transaction_id=$transaction", $auth, json_encode($server, JSON_THROW_ON_ERROR));
                }
            }
            $this->call('PUT', "$base/transactions/$transaction", $auth);
        } catch (\Throwable $e) {
            try {
                $this->call('DELETE', "$base/transactions/$transaction", $auth);
            } catch (\Throwable) {
                // The original failure is the one to report.
            }
            throw $e;
        }
    }

    /** A server name HAProxy accepts, derived from the node's name. */
    public static function serverName(string $node): string
    {
        return 'aybak-' . substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $node), 0, 120);
    }

    /**
     * @param array{0: string, 1: string} $auth
     */
    private function call(string $method, string $url, array $auth, ?string $body = null): Response
    {
        $headers = ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $options = ['basic_auth' => $auth];
        if ($this->caFile !== null) {
            $options['ca_file'] = $this->caFile;
        }
        $response = $this->http->request($method, $url, $headers, $body, $options);
        if (!$response->ok()) {
            throw new \RuntimeException("Data Plane API $method " . CurlTransport::redact(strtok($url, '?')) . " answered HTTP {$response->status}.");
        }

        return $response;
    }
}
