<?php

declare(strict_types=1);

namespace Aybak\Http;

/**
 * One HTTP request and its answer. The outputs and the Kubernetes client talk
 * through this, so the tests can record every request without a network.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param array{ca_file?: string, basic_auth?: array{0: string, 1: string}, timeout?: int} $options
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): Response;
}
