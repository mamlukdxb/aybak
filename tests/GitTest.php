<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\NodeSet;
use Aybak\Output\Git;
use PHPUnit\Framework\TestCase;

/**
 * Real git against a local bare repository. The SSH options aybak sets are not
 * exercised by a file:// URL; the kind test covers them against a git server.
 */
final class GitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aybak-git-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->sh("git init --quiet --bare --initial-branch=master {$this->dir}/remote.git");
        $this->sh("git clone --quiet {$this->dir}/remote.git {$this->dir}/seed");
        $this->sh("cd {$this->dir}/seed && git -c user.name=t -c user.email=t@t commit --quiet --allow-empty -m init && git push --quiet origin HEAD:master");
    }

    protected function tearDown(): void
    {
        $this->sh('rm -rf ' . escapeshellarg($this->dir));
    }

    private function sh(string $cmd): string
    {
        exec($cmd . ' 2>&1', $out, $rc);
        self::assertSame(0, $rc, "$cmd: " . implode("\n", $out));

        return implode("\n", $out);
    }

    private function git(): Git
    {
        return new Git("file://{$this->dir}/remote.git", 'master', 'nodes/beyazid.json', "{$this->dir}/work", 'Aybak', 'aybak@example.org',
            keyFile: '/nonexistent-key', knownHostsFile: '/nonexistent-known-hosts');
    }

    private function commits(): int
    {
        return (int) $this->sh("git --git-dir={$this->dir}/remote.git rev-list --count master");
    }

    public function testASetIsCommittedOnceAndNotAgainWhenTheNodesAreTheSame(): void
    {
        $set = NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'calico');

        $this->git()->send($set, new \DateTimeImmutable('2026-11-11T10:00:00Z'));
        self::assertSame(2, $this->commits());

        // A new aybak, a new time, the same nodes: no commit.
        $this->sh("rm -rf {$this->dir}/work");
        $this->git()->send($set, new \DateTimeImmutable('2026-11-11T11:00:00Z'));
        self::assertSame(2, $this->commits());

        $file = json_decode($this->sh("git --git-dir={$this->dir}/remote.git show master:nodes/beyazid.json"), true);
        self::assertSame(['10.1.0.4', '10.1.0.6', '10.1.0.5'], array_column($file['nodes'], 'address'));
        self::assertSame('2026-11-11T10:00:00Z', $file['generated']);
    }

    public function testAChangedSetIsCommittedOnTopOfOthersWork(): void
    {
        $git = $this->git();
        $git->send(NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'calico'), new \DateTimeImmutable());

        // Someone else pushes to the same branch in between.
        $this->sh("cd {$this->dir}/seed && git pull --quiet origin master && echo x > other && git add other && git -c user.name=t -c user.email=t@t commit --quiet -m other && git push --quiet origin HEAD:master");

        $nodes = Fixtures::beyazid();
        $nodes[] = Fixtures::node('w-new', '5.0.0.9', '10.1.0.9/32');
        $git->send(NodeSet::fromNodeList('beyazid', $nodes, 'workers', 'calico'), new \DateTimeImmutable());

        self::assertSame(4, $this->commits());
        self::assertStringContainsString('aybak: beyazid, 4 node(s)', $this->sh("git --git-dir={$this->dir}/remote.git log -1 --format=%s master"));
        self::assertSame('x', trim($this->sh("git --git-dir={$this->dir}/remote.git show master:other")));
    }

    public function testAnUnreachableRepositoryIsAFailure(): void
    {
        $git = new Git("file://{$this->dir}/missing.git", 'master', 'n.json', "{$this->dir}/work2", 'A', 'a@b', keyFile: '/k', knownHostsFile: '/h');

        $this->expectExceptionMessage('git clone failed');
        $git->send(NodeSet::fromNodeList('c', Fixtures::beyazid(), 'workers', 'calico'), new \DateTimeImmutable());
    }
}
