<?php

declare(strict_types=1);

namespace Aybak;

use Aybak\Output\Output;

/**
 * One pass: read the nodes, and send the set to every output that has not yet
 * received it.
 *
 * A FAILED OR EMPTY READ SENDS NOTHING. A load balancer emptied because the
 * API server was briefly unreachable would take the whole cluster offline, so
 * the last good set stays where it is until a read succeeds with nodes in it.
 *
 * Each output remembers the fingerprint of the last set it accepted. A failed
 * output is tried again on the next pass without holding back the others.
 * Readiness reflects the outputs: the Pod is Ready only when the last read
 * succeeded and every output holds the current set.
 */
final class Runner
{
    /** @var array<string, string> output name => fingerprint it last accepted */
    private array $delivered = [];

    /**
     * @param list<Output>                         $outputs
     * @param \Closure(): list<array<string, mixed>> $readNodes
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $readNodes,
        private readonly array $outputs,
        private readonly Log $log,
        private readonly string $statusDir,
    ) {
    }

    /** @return bool whether every output holds the current set */
    public function pass(\DateTimeImmutable $now): bool
    {
        $this->touch('alive');
        try {
            $items = ($this->readNodes)();
        } catch (\Throwable $e) {
            $this->log->error('Reading the nodes failed; nothing was sent.', ['error' => $e->getMessage()]);

            return $this->ready(false);
        }

        $set = NodeSet::fromNodeList($this->config->cluster, $items, $this->config->nodes, $this->config->address);
        if ($set->isEmpty()) {
            $this->log->warning('No node qualified; nothing was sent, and every output keeps its last set.', [
                'listed' => count($items), 'nodes' => $this->config->nodes, 'address' => $this->config->address,
            ]);

            return $this->ready(false);
        }

        $fingerprint = $set->fingerprint();
        $all = true;
        foreach ($this->outputs as $output) {
            if (($this->delivered[$output->name()] ?? null) === $fingerprint) {
                continue;
            }
            try {
                $output->send($set, $now);
                $this->delivered[$output->name()] = $fingerprint;
                $this->log->info('Node set sent.', ['output' => $output->name(), 'nodes' => count($set->nodes)]);
            } catch (\Throwable $e) {
                $all = false;
                $this->log->error('Sending the node set failed; it will be tried again.', ['output' => $output->name(), 'error' => $e->getMessage()]);
            }
        }

        return $this->ready($all);
    }

    private function ready(bool $ready): bool
    {
        $file = "{$this->statusDir}/ready";
        if ($ready) {
            $this->touch('ready');
        } elseif (is_file($file)) {
            unlink($file);
        }

        return $ready;
    }

    private function touch(string $name): void
    {
        file_put_contents("{$this->statusDir}/$name", (string) time());
    }
}
