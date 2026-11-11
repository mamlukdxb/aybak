<?php

declare(strict_types=1);

namespace Aybak\Http;

final readonly class Response
{
    public function __construct(public int $status, public string $body)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new \UnexpectedValueException('The answer is not a JSON object or list.');
    }
}
