<?php

declare(strict_types=1);

namespace Aybak\Tests;

use Aybak\Config;
use Aybak\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const array GIT = [
        'AYBAK_CLUSTER' => 'beyazid',
        'AYBAK_OUTPUTS' => 'git',
        'AYBAK_GIT_URL' => 'ssh://git@example.org/gateways.git',
        'AYBAK_GIT_KEY_FILE' => '/run/aybak/git-key',
        'AYBAK_GIT_KNOWN_HOSTS_FILE' => '/run/aybak/known_hosts',
    ];

    private const array GIT_HTTPS = [
        'AYBAK_CLUSTER' => 'beyazid',
        'AYBAK_OUTPUTS' => 'git',
        'AYBAK_GIT_URL' => 'https://git.example.org/lb/gateways.git',
        'AYBAK_GIT_USER' => 'aybak',
        'AYBAK_GIT_TOKEN_FILE' => '/run/aybak/token',
    ];

    public function testTheGitTransportFollowsTheUrl(): void
    {
        self::assertSame('ssh', Config::fromEnvironment(self::GIT)->gitTransport());
        self::assertSame('ssh', Config::fromEnvironment(['AYBAK_GIT_URL' => 'git@example.org:lb/gateways.git'] + self::GIT)->gitTransport());
        $https = Config::fromEnvironment(self::GIT_HTTPS);
        self::assertSame('https', $https->gitTransport());
        self::assertSame('aybak', $https->gitUser);
        self::assertSame('/run/aybak/token', $https->gitTokenFile);
    }

    public function testDefaults(): void
    {
        $c = Config::fromEnvironment(self::GIT);

        self::assertSame('workers', $c->nodes);
        self::assertSame('InternalIP', $c->address);
        self::assertSame(15, $c->resyncSeconds);
        self::assertSame('master', $c->gitBranch);
        self::assertSame('nodes/beyazid.json', $c->gitPath);
    }

    public function testDataplaneBackendsAreParsed(): void
    {
        $c = Config::fromEnvironment([
            'AYBAK_CLUSTER' => 'c', 'AYBAK_OUTPUTS' => 'dataplane', 'AYBAK_DATAPLANE_URL' => 'https://lb:5555',
            'AYBAK_DATAPLANE_USER' => 'aybak', 'AYBAK_DATAPLANE_PASSWORD_FILE' => '/run/p',
            'AYBAK_DATAPLANE_BACKENDS' => 'http_back:32080, https_back:32443', 'AYBAK_DATAPLANE_SEND_PROXY_V2' => 'yes',
        ]);

        self::assertSame(['http_back' => 32080, 'https_back' => 32443], $c->dataplaneBackends);
        self::assertTrue($c->dataplaneSendProxyV2);
        self::assertNull($c->caFile);
    }

    public function testACaFileIsRead(): void
    {
        $c = Config::fromEnvironment(['AYBAK_CLUSTER' => 'c', 'AYBAK_OUTPUTS' => 'webhook', 'AYBAK_WEBHOOK_URL' => 'https://x',
            'AYBAK_WEBHOOK_KEY_FILE' => '/k', 'AYBAK_CA_FILE' => '/etc/aybak/ca/ca.crt']);

        self::assertSame('/etc/aybak/ca/ca.crt', $c->caFile);
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('refused')]
    public function testARefusedConfigurationStops(array $env, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);
        Config::fromEnvironment($env);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function refused(): iterable
    {
        yield 'no cluster' => [['AYBAK_OUTPUTS' => 'git'], 'AYBAK_CLUSTER is required'];
        yield 'bad cluster name' => [['AYBAK_CLUSTER' => 'Bad Name', 'AYBAK_OUTPUTS' => 'git'], 'lower-case'];
        yield 'no output' => [['AYBAK_CLUSTER' => 'c'], 'at least one'];
        yield 'unknown output' => [['AYBAK_CLUSTER' => 'c', 'AYBAK_OUTPUTS' => 'git,ftp'], 'unknown output: ftp'];
        yield 'unknown selection' => [self::GIT + ['AYBAK_NODES' => 'masters'], 'AYBAK_NODES'];
        yield 'unknown address' => [self::GIT + ['AYBAK_ADDRESS' => 'Hostname'], 'AYBAK_ADDRESS'];
        yield 'git without key' => [array_diff_key(self::GIT, ['AYBAK_GIT_KEY_FILE' => 1]), 'AYBAK_GIT_KEY_FILE is required'];
        yield 'git without known hosts' => [array_diff_key(self::GIT, ['AYBAK_GIT_KNOWN_HOSTS_FILE' => 1]), 'AYBAK_GIT_KNOWN_HOSTS_FILE is required'];
        yield 'git without url' => [array_diff_key(self::GIT, ['AYBAK_GIT_URL' => 1]), 'AYBAK_GIT_URL is required'];
        yield 'git over http' => [['AYBAK_GIT_URL' => 'http://git.example.org/gateways.git'] + self::GIT_HTTPS, 'ssh:// or https://'];
        yield 'git file url' => [['AYBAK_GIT_URL' => 'file:///srv/gateways.git'] + self::GIT, 'ssh:// or https://'];
        yield 'https with a user in the url' => [['AYBAK_GIT_URL' => 'https://aybak@git.example.org/gateways.git'] + self::GIT_HTTPS, 'must not carry a user or password'];
        yield 'https with a password in the url' => [['AYBAK_GIT_URL' => 'https://aybak:t0ken@git.example.org/gateways.git'] + self::GIT_HTTPS, 'must not carry a user or password'];
        yield 'https without user' => [array_diff_key(self::GIT_HTTPS, ['AYBAK_GIT_USER' => 1]), 'AYBAK_GIT_USER is required'];
        yield 'https without token' => [array_diff_key(self::GIT_HTTPS, ['AYBAK_GIT_TOKEN_FILE' => 1]), 'AYBAK_GIT_TOKEN_FILE is required'];
        yield 'https user with a space' => [['AYBAK_GIT_USER' => "a b"] + self::GIT_HTTPS, 'AYBAK_GIT_USER must be'];
        yield 'https with an ssh key' => [self::GIT_HTTPS + ['AYBAK_GIT_KEY_FILE' => '/k'], 'AYBAK_GIT_KEY_FILE does not apply'];
        yield 'ssh with a token' => [self::GIT + ['AYBAK_GIT_TOKEN_FILE' => '/t'], 'AYBAK_GIT_TOKEN_FILE does not apply'];
        yield 'webhook over http' => [['AYBAK_CLUSTER' => 'c', 'AYBAK_OUTPUTS' => 'webhook', 'AYBAK_WEBHOOK_URL' => 'http://x', 'AYBAK_WEBHOOK_KEY_FILE' => '/k'], 'https://'];
        yield 'dataplane without backends' => [['AYBAK_CLUSTER' => 'c', 'AYBAK_OUTPUTS' => 'dataplane', 'AYBAK_DATAPLANE_URL' => 'https://lb', 'AYBAK_DATAPLANE_USER' => 'u', 'AYBAK_DATAPLANE_PASSWORD_FILE' => '/p'], 'AYBAK_DATAPLANE_BACKENDS is required'];
        yield 'bad backend' => [['AYBAK_CLUSTER' => 'c', 'AYBAK_OUTPUTS' => 'dataplane', 'AYBAK_DATAPLANE_URL' => 'https://lb', 'AYBAK_DATAPLANE_USER' => 'u', 'AYBAK_DATAPLANE_PASSWORD_FILE' => '/p', 'AYBAK_DATAPLANE_BACKENDS' => 'http_back'], '<backend>:<port>'];
        yield 'resync too short' => [self::GIT + ['AYBAK_RESYNC_SECONDS' => '1'], 'between 5 and 300'];
    }
}
