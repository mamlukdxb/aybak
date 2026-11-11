<?php

declare(strict_types=1);

namespace Aybak;

use Aybak\Http\Transport;

/**
 * Lists the cluster's nodes with the Pod's own ServiceAccount.
 *
 * Aybak lists the nodes every AYBAK_RESYNC_SECONDS (15 by default) rather than
 * holding a watch open. A list is one small request, it cannot miss an event,
 * and it keeps every change within the one minute aybak promises. Its
 * ClusterRole needs get, list and watch on nodes and nothing else.
 */
final readonly class Kubernetes
{
    private const string SA_DIR = '/var/run/secrets/kubernetes.io/serviceaccount';

    public function __construct(
        private Transport $http,
        private string $apiUrl,
        private string $tokenFile,
        private string $caFile,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function inCluster(Transport $http, array $env): self
    {
        $host = $env['KUBERNETES_SERVICE_HOST'] ?? throw new ConfigException('KUBERNETES_SERVICE_HOST is not set: aybak must run in a Pod, or be given AYBAK_K8S_API.');
        $port = $env['KUBERNETES_SERVICE_PORT'] ?? '443';
        $host = str_contains($host, ':') ? "[$host]" : $host;

        return new self(
            $http,
            $env['AYBAK_K8S_API'] ?? "https://$host:$port",
            $env['AYBAK_K8S_TOKEN_FILE'] ?? self::SA_DIR . '/token',
            $env['AYBAK_K8S_CA_FILE'] ?? self::SA_DIR . '/ca.crt',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        // Read on every call: the kubelet rotates the projected token.
        $token = @file_get_contents($this->tokenFile);
        if ($token === false || trim($token) === '') {
            throw new \RuntimeException("The ServiceAccount token at {$this->tokenFile} could not be read.");
        }
        $response = $this->http->request('GET', rtrim($this->apiUrl, '/') . '/api/v1/nodes', [
            'Authorization' => 'Bearer ' . trim($token),
            'Accept' => 'application/json',
        ], null, ['ca_file' => $this->caFile]);
        if (!$response->ok()) {
            throw new \RuntimeException("Listing nodes answered HTTP {$response->status}.");
        }
        $list = $response->json();
        if (($list['kind'] ?? null) !== 'NodeList' || !is_array($list['items'] ?? null)) {
            throw new \RuntimeException('Listing nodes did not answer a NodeList.');
        }

        return array_values($list['items']);
    }
}
