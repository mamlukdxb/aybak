<?php

declare(strict_types=1);

namespace Aybak\Http;

final class CurlTransport implements Transport
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): Response
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (isset($options['ca_file'])) {
            curl_setopt($ch, CURLOPT_CAINFO, $options['ca_file']);
        }
        if (isset($options['basic_auth'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $options['basic_auth'][0] . ':' . $options['basic_auth'][1]);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            // curl's message names the host and the failure, never a header value.
            throw new TransportException("$method " . self::redact($url) . ' failed: ' . curl_error($ch));
        }

        return new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $out);
    }

    /** A URL as it may be logged: without any user or password part. */
    public static function redact(string $url): string
    {
        return (string) preg_replace('#^(\w+://)[^/@]*@#', '$1', $url);
    }
}
