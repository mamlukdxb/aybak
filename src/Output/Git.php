<?php

declare(strict_types=1);

namespace Aybak\Output;

use Aybak\NodeSet;

/**
 * Writes the node set to a file in a git repository and pushes it.
 *
 * Over SSH, it trusts only the host keys in the configured known_hosts file
 * (StrictHostKeyChecking=yes): a host key is never learned on first contact.
 * Over HTTPS, it sends a user and a token read from a file, through a
 * credential helper, so the token is never in an argument, the environment or
 * a log line. A commit is made only when the nodes in the file differ from the
 * set, so a restarted aybak does not commit the same nodes again with a new
 * time.
 */
final readonly class Git implements Output
{
    /**
     * Answers git's credential requests from the token file. git runs it with
     * an argument of get, store or erase; only get is answered, and nothing is
     * ever stored. The token is copied from the file to standard output by tr,
     * so it is in no argument of any process.
     */
    private const string CREDENTIAL_HELPER = '!f() { test "$1" = get || exit 0; '
        . 'printf "username=%s\npassword=" "$AYBAK_GIT_HELPER_USER"; '
        . 'tr -d "\r\n" < "$AYBAK_GIT_HELPER_TOKEN_FILE"; printf "\n"; }; f';

    /**
     * Give either $keyFile and $knownHostsFile (SSH), or $user and $tokenFile
     * (HTTPS). $caFile, when given, replaces the public authorities for HTTPS.
     */
    public function __construct(
        private string $url,
        private string $branch,
        private string $path,
        private string $workDir,
        private string $authorName,
        private string $authorEmail,
        private ?string $keyFile = null,
        private ?string $knownHostsFile = null,
        private ?string $user = null,
        private ?string $tokenFile = null,
        private ?string $caFile = null,
    ) {
    }

    public function name(): string
    {
        return 'git';
    }

    public function send(NodeSet $set, \DateTimeImmutable $at): void
    {
        if (!is_dir("{$this->workDir}/.git")) {
            $this->git(['clone', '--quiet', '--branch', $this->branch, '--single-branch', $this->url, $this->workDir], null);
        } else {
            $this->git(['fetch', '--quiet', 'origin', $this->branch]);
            $this->git(['reset', '--quiet', '--hard', "origin/{$this->branch}"]);
        }

        $file = "{$this->workDir}/{$this->path}";
        $current = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($current) && ($current['nodes'] ?? null) === $set->nodes) {
            return;
        }

        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) {
            throw new \RuntimeException("Could not create the directory for {$this->path}.");
        }
        file_put_contents($file, json_encode($set->toArray($at), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->git(['add', '--', $this->path]);
        $this->git([
            '-c', "user.name={$this->authorName}", '-c', "user.email={$this->authorEmail}",
            'commit', '--quiet', '-m', "aybak: {$set->cluster}, " . count($set->nodes) . ' node(s)',
        ]);
        $this->git(['push', '--quiet', 'origin', "HEAD:{$this->branch}"]);
    }

    /**
     * The options and environment for the configured method.
     *
     * @return array{list<string>, array<string, string>}
     */
    private function transport(): array
    {
        if ($this->tokenFile !== null) {
            // The empty helper first clears any helper configured elsewhere,
            // so only this one is asked and none can store the token.
            $options = ['-c', 'credential.helper=', '-c', 'credential.helper=' . self::CREDENTIAL_HELPER, '-c', 'http.sslVerify=true'];
            if ($this->caFile !== null) {
                $options = [...$options, '-c', "http.sslCAInfo={$this->caFile}"];
            }

            return [$options, [
                'AYBAK_GIT_HELPER_USER' => (string) $this->user,
                'AYBAK_GIT_HELPER_TOKEN_FILE' => $this->tokenFile,
            ]];
        }

        $ssh = sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%s -o BatchMode=yes',
            escapeshellarg((string) $this->keyFile),
            escapeshellarg((string) $this->knownHostsFile),
        );

        return [[], ['GIT_SSH_COMMAND' => $ssh]];
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args, ?string $cwd = ''): void
    {
        [$options, $transportEnv] = $this->transport();
        $env = $transportEnv + [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'HOME' => sys_get_temp_dir(),
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        $process = proc_open(
            array_merge(['git'], $options, $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd === '' ? $this->workDir : $cwd,
            $env,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('git could not be started.');
        }
        stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $first = strtok($err, "\n") ?: 'no message';
            $command = $args[0] === '-c' ? $args[4] : $args[0];
            throw new \RuntimeException("git $command failed: $first");
        }
    }
}
