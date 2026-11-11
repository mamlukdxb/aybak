<?php

declare(strict_types=1);

namespace Aybak\Output;

use Aybak\NodeSet;

/**
 * Writes the node set to a file in a git repository and pushes it.
 *
 * SSH trusts only the host keys in the configured known_hosts file
 * (StrictHostKeyChecking=yes): a host key is never learned on first contact.
 * A commit is made only when the nodes in the file differ from the set, so a
 * restarted aybak does not commit the same nodes again with a new time.
 */
final readonly class Git implements Output
{
    public function __construct(
        private string $url,
        private string $branch,
        private string $path,
        private string $keyFile,
        private string $knownHostsFile,
        private string $workDir,
        private string $authorName,
        private string $authorEmail,
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
     * @param list<string> $args
     */
    private function git(array $args, ?string $cwd = ''): void
    {
        $ssh = sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%s -o BatchMode=yes',
            escapeshellarg($this->keyFile),
            escapeshellarg($this->knownHostsFile),
        );
        $env = ['GIT_SSH_COMMAND' => $ssh, 'GIT_TERMINAL_PROMPT' => '0', 'HOME' => sys_get_temp_dir(), 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $process = proc_open(
            array_merge(['git'], $args),
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
