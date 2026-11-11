<?php

declare(strict_types=1);

namespace Aybak;

/**
 * One JSON object per line on stdout. Messages are built by aybak itself and
 * never include a credential; the tests assert that.
 */
final class Log
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDOUT;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = ['time' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'message' => $message] + $context;
        fwrite($this->stream, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }
}
