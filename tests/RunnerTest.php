<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\Config;
use Aybak\Log;
use Aybak\NodeSet;
use Aybak\Output\Output;
use Aybak\Runner;
use PHPUnit\Framework\TestCase;

final class RecordingOutput implements Output
{
    /** @var list<NodeSet> */
    public array $sent = [];
    public int $failures = 0;

    public function __construct(private string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(NodeSet $set, \DateTimeImmutable $at): void
    {
        if ($this->failures > 0) {
            $this->failures--;
            throw new \RuntimeException('refused');
        }
        $this->sent[] = $set;
    }
}

final class RunnerTest extends TestCase
{
    private string $dir;
    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aybak-runner-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->logStream = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    private function config(): Config
    {
        return Config::fromEnvironment(['AYBAK_CLUSTER' => 'beyazid', 'AYBAK_OUTPUTS' => 'webhook', 'AYBAK_ADDRESS' => 'calico',
            'AYBAK_WEBHOOK_URL' => 'https://x', 'AYBAK_WEBHOOK_KEY_FILE' => '/k']);
    }

    /**
     * @param \Closure(): list<array<string, mixed>> $read
     * @param list<Output>                           $outputs
     */
    private function runner(\Closure $read, array $outputs): Runner
    {
        return new Runner($this->config(), $read, $outputs, new Log($this->logStream), $this->dir);
    }

    private function log(): string
    {
        rewind($this->logStream);

        return (string) stream_get_contents($this->logStream);
    }

    public function testASetIsSentOnceAndNotAgainUntilItChanges(): void
    {
        $nodes = Fixtures::beyazid();
        $out = new RecordingOutput('a');
        $runner = $this->runner(static function () use (&$nodes): array { return $nodes; }, [$out]);

        self::assertTrue($runner->pass(new \DateTimeImmutable()));
        self::assertTrue($runner->pass(new \DateTimeImmutable()));
        self::assertCount(1, $out->sent);
        self::assertFileExists("{$this->dir}/ready");

        $nodes[] = Fixtures::node('w-new', '5.0.0.9', '10.1.0.9/32');
        $runner->pass(new \DateTimeImmutable());
        self::assertCount(2, $out->sent);
        self::assertSame('10.1.0.9', array_column($out->sent[1]->nodes, 'address', 'name')['w-new']);
    }

    /** The property the whole design rests on: a failed read must never empty a load balancer. */
    public function testAFailedReadSendsNothingAndIsNotReady(): void
    {
        $out = new RecordingOutput('a');
        $runner = $this->runner(static fn (): array => throw new \RuntimeException('API down'), [$out]);

        self::assertFalse($runner->pass(new \DateTimeImmutable()));
        self::assertSame([], $out->sent);
        self::assertFileDoesNotExist("{$this->dir}/ready");
        self::assertFileExists("{$this->dir}/alive");
        self::assertStringContainsString('nothing was sent', $this->log());
    }

    public function testAnEmptySetSendsNothing(): void
    {
        $out = new RecordingOutput('a');
        $notReady = array_map(static fn (array $n): array => array_replace_recursive($n, ['status' => ['conditions' => [1 => ['status' => 'False']]]]), Fixtures::beyazid());
        $runner = $this->runner(static fn (): array => $notReady, [$out]);

        self::assertFalse($runner->pass(new \DateTimeImmutable()));
        self::assertSame([], $out->sent);
    }

    public function testAFailedOutputIsRetriedWithoutHoldingBackTheOthers(): void
    {
        $failing = new RecordingOutput('failing');
        $failing->failures = 1;
        $fine = new RecordingOutput('fine');
        $runner = $this->runner(static fn (): array => Fixtures::beyazid(), [$failing, $fine]);

        self::assertFalse($runner->pass(new \DateTimeImmutable()));
        self::assertCount(0, $failing->sent);
        self::assertCount(1, $fine->sent);

        self::assertTrue($runner->pass(new \DateTimeImmutable()));
        self::assertCount(1, $failing->sent);
        self::assertCount(1, $fine->sent);
    }

    public function testNoCredentialReachesTheLog(): void
    {
        $out = new RecordingOutput('a');
        $out->failures = 1;
        $runner = $this->runner(static fn (): array => Fixtures::beyazid(), [$out]);
        $runner->pass(new \DateTimeImmutable());

        $log = $this->log();
        self::assertNotSame('', $log);
        foreach (['webhook-shared-key', 'dataplane-password', 'sa-token', 'BEGIN OPENSSH'] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
        foreach (explode("\n", trim($log)) as $line) {
            self::assertIsArray(json_decode($line, true), "Every log line is one JSON object: $line");
        }
    }
}
