<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\NodeSet;
use Aybak\Output\Git;
use PHPUnit\Framework\TestCase;

/**
 * The git output's token method, against a real smart-HTTP git server that
 * demands Basic authentication (fixtures/git-http-server.php). The server
 * speaks plain HTTP on the loopback interface: the credential path is the
 * same as over HTTPS, and Config refuses http:// for a real repository.
 *
 * A wrapper named git, first on the PATH, records every argument and the
 * environment of each git that aybak starts, so the tests can show the token
 * is in neither. The last test is the negative control: a token placed in the
 * URL is found in that record.
 */
final class GitHttpsTest extends TestCase
{
    private string $dir;
    private string $token;
    private string $path;
    /** @var resource */
    private $server;
    private int $port;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aybak-https-' . bin2hex(random_bytes(4));
        $this->token = bin2hex(random_bytes(20));
        mkdir("{$this->dir}/bin", 0700, true);
        $this->sh("git init --quiet --bare --initial-branch=master {$this->dir}/root/gateways.git");
        $this->sh("git clone --quiet {$this->dir}/root/gateways.git {$this->dir}/seed");
        $this->sh("cd {$this->dir}/seed && git -c user.name=t -c user.email=t@t commit --quiet --allow-empty -m init && git push --quiet origin HEAD:master");
        file_put_contents("{$this->dir}/token", $this->token . "\n");

        $real = trim($this->sh('command -v git'));
        // Only a git that aybak starts is recorded: aybak alone sets
        // GIT_CONFIG_NOSYSTEM, and the tests' own git calls carry the
        // environment of whoever runs the tests, which is not for a log.
        file_put_contents("{$this->dir}/bin/git", "#!/bin/sh\n"
            . "[ \"\${GIT_CONFIG_NOSYSTEM:-}\" = 1 ] && { printf 'ARGV:'; printf ' %s' \"\$@\"; printf '\\n'; env; } >> {$this->dir}/record\n"
            . "exec $real \"\$@\"\n");
        chmod("{$this->dir}/bin/git", 0700);
        $this->path = (string) getenv('PATH');
        putenv("PATH={$this->dir}/bin:{$this->path}");

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/fixtures/git-http-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [
                'AYBAK_TEST_USER' => 'aybak-test',
                'AYBAK_TEST_TOKEN' => $this->token,
                'AYBAK_TEST_ROOT' => "{$this->dir}/root",
                'AYBAK_TEST_AUTH_LOG' => "{$this->dir}/auth-log",
                'PATH' => $this->path,
            ],
        );
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $this->port) === false; $i++) {
            usleep(100_000);
        }
    }

    protected function tearDown(): void
    {
        proc_terminate($this->server);
        proc_close($this->server);
        putenv("PATH={$this->path}");
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function sh(string $cmd): string
    {
        exec($cmd . ' 2>&1', $out, $rc);
        self::assertSame(0, $rc, "$cmd: " . implode("\n", $out));

        return implode("\n", $out);
    }

    private function git(string $url, string $tokenFile): Git
    {
        return new Git($url, 'master', 'nodes/beyazid.json', "{$this->dir}/work", 'Aybak', 'aybak@example.org',
            user: 'aybak-test', tokenFile: $tokenFile);
    }

    private function url(): string
    {
        return "http://127.0.0.1:{$this->port}/gateways.git";
    }

    private function commits(): int
    {
        return (int) $this->sh("git --git-dir={$this->dir}/root/gateways.git rev-list --count master");
    }

    private function set(): NodeSet
    {
        return NodeSet::fromNodeList('beyazid', Fixtures::beyazid(), 'workers', 'calico');
    }

    public function testASetIsPushedWithTheTokenAndTheTokenIsInNoArgumentOrEnvironment(): void
    {
        $this->git($this->url(), "{$this->dir}/token")->send($this->set(), new \DateTimeImmutable('2026-11-12T10:00:00Z'));

        self::assertSame(2, $this->commits());
        self::assertStringContainsString('aybak: beyazid, 3 node(s)', $this->sh("git --git-dir={$this->dir}/root/gateways.git log -1 --format=%s master"));
        // The server admitted requests carrying the user, so the credential
        // was sent; and git was started with it nowhere in sight.
        self::assertStringContainsString('aybak-test', (string) file_get_contents("{$this->dir}/auth-log"));
        $record = (string) file_get_contents("{$this->dir}/record");
        self::assertGreaterThanOrEqual(3, substr_count($record, 'ARGV:'));
        // assertTrue and assertFalse, so that a failure never prints the record.
        self::assertTrue(str_contains($record, "AYBAK_GIT_HELPER_TOKEN_FILE={$this->dir}/token"), 'The environment was not recorded.');
        self::assertFalse(str_contains($record, $this->token), 'The token is in an argument or the environment of git.');
    }

    public function testAWrongTokenIsRefusedAndNothingIsCommitted(): void
    {
        file_put_contents("{$this->dir}/wrong", substr($this->token, 0, -1) . 'x');

        try {
            $this->git($this->url(), "{$this->dir}/wrong")->send($this->set(), new \DateTimeImmutable());
            self::fail('A wrong token was accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('git clone failed', $e->getMessage());
            self::assertFalse(str_contains($e->getMessage(), $this->token), 'The token is in the error message.');
        }
        self::assertSame(1, $this->commits());
    }

    public function testACertificateAuthorityFileIsGivenToGit(): void
    {
        $git = new Git($this->url(), 'master', 'n.json', "{$this->dir}/work", 'A', 'a@b',
            user: 'aybak-test', tokenFile: "{$this->dir}/token", caFile: '/etc/aybak/ca/ca.crt');
        $git->send($this->set(), new \DateTimeImmutable());

        self::assertTrue(str_contains((string) file_get_contents("{$this->dir}/record"), 'http.sslCAInfo=/etc/aybak/ca/ca.crt'), 'git was not given the CA file.');
    }

    public function testControlATokenInTheUrlIsFoundInTheRecord(): void
    {
        // Negative control for the first test: with the credential in the URL
        // instead of the token file, the same record does show the token. An
        // empty token file keeps the helper from supplying it.
        file_put_contents("{$this->dir}/empty", '');
        $url = "http://aybak-test:{$this->token}@127.0.0.1:{$this->port}/gateways.git";
        $this->git($url, "{$this->dir}/empty")->send($this->set(), new \DateTimeImmutable());

        self::assertSame(2, $this->commits());
        self::assertTrue(str_contains((string) file_get_contents("{$this->dir}/record"), $this->token), 'The record did not catch a token in the URL.');
    }
}
