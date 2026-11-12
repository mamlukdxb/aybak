<?php

declare(strict_types=1);

namespace Aybak;

/**
 * Aybak's settings, read once from the environment.
 *
 * Every credential is read from a FILE named by a setting, never from the
 * setting itself, so that no secret value is ever in the process environment,
 * a log line or an error message. A setting that is required by an enabled
 * output and is missing stops aybak before it reads a single node.
 */
final readonly class Config
{
    public const array NODE_SELECTIONS = ['workers', 'all'];
    public const array ADDRESS_SOURCES = ['InternalIP', 'ExternalIP', 'calico'];
    public const array OUTPUTS = ['git', 'webhook', 'dataplane'];

    /**
     * @param list<string>              $outputs
     * @param array<string, int>        $dataplaneBackends backend name => server port
     */
    public function __construct(
        public string $cluster,
        public string $nodes,
        public string $address,
        public array $outputs,
        public int $resyncSeconds,
        public string $stateDir,
        public ?string $gitUrl,
        public string $gitBranch,
        public string $gitPath,
        public ?string $gitKeyFile,
        public ?string $gitKnownHostsFile,
        public ?string $gitUser,
        public ?string $gitTokenFile,
        public string $gitAuthorName,
        public string $gitAuthorEmail,
        public ?string $webhookUrl,
        public ?string $webhookKeyFile,
        public ?string $dataplaneUrl,
        public ?string $dataplaneUser,
        public ?string $dataplanePasswordFile,
        public array $dataplaneBackends,
        public bool $dataplaneSendProxyV2,
        public ?string $caFile = null,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $get = static fn (string $k, ?string $default = null): ?string
            => isset($env[$k]) && trim($env[$k]) !== '' ? trim($env[$k]) : $default;

        $cluster = $get('AYBAK_CLUSTER') ?? throw new ConfigException('AYBAK_CLUSTER is required: the name written beside every node set.');
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $cluster) !== 1) {
            throw new ConfigException('AYBAK_CLUSTER must be lower-case letters, digits, dots and hyphens.');
        }

        $nodes = $get('AYBAK_NODES', 'workers');
        if (!in_array($nodes, self::NODE_SELECTIONS, true)) {
            throw new ConfigException('AYBAK_NODES must be one of: ' . implode(', ', self::NODE_SELECTIONS) . '.');
        }

        $address = $get('AYBAK_ADDRESS', 'InternalIP');
        if (!in_array($address, self::ADDRESS_SOURCES, true)) {
            throw new ConfigException('AYBAK_ADDRESS must be one of: ' . implode(', ', self::ADDRESS_SOURCES) . '.');
        }

        $outputs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $get('AYBAK_OUTPUTS', ''))))));
        if ($outputs === []) {
            throw new ConfigException('AYBAK_OUTPUTS must name at least one of: ' . implode(', ', self::OUTPUTS) . '.');
        }
        foreach ($outputs as $o) {
            if (!in_array($o, self::OUTPUTS, true)) {
                throw new ConfigException("AYBAK_OUTPUTS names an unknown output: $o.");
            }
        }

        $resync = (int) $get('AYBAK_RESYNC_SECONDS', '15');
        if ($resync < 5 || $resync > 300) {
            throw new ConfigException('AYBAK_RESYNC_SECONDS must be between 5 and 300.');
        }

        $backends = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $get('AYBAK_DATAPLANE_BACKENDS', '')))) as $pair) {
            if (preg_match('/^([A-Za-z0-9_.-]+):([0-9]{1,5})$/', $pair, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 65535) {
                throw new ConfigException("AYBAK_DATAPLANE_BACKENDS entry is not <backend>:<port>: $pair.");
            }
            $backends[$m[1]] = (int) $m[2];
        }

        $config = new self(
            cluster: $cluster,
            nodes: $nodes,
            address: $address,
            outputs: $outputs,
            resyncSeconds: $resync,
            stateDir: $get('AYBAK_STATE_DIR', '/var/lib/aybak'),
            gitUrl: $get('AYBAK_GIT_URL'),
            gitBranch: $get('AYBAK_GIT_BRANCH', 'master'),
            gitPath: $get('AYBAK_GIT_PATH', "nodes/$cluster.json"),
            gitKeyFile: $get('AYBAK_GIT_KEY_FILE'),
            gitKnownHostsFile: $get('AYBAK_GIT_KNOWN_HOSTS_FILE'),
            gitUser: $get('AYBAK_GIT_USER'),
            gitTokenFile: $get('AYBAK_GIT_TOKEN_FILE'),
            gitAuthorName: $get('AYBAK_GIT_AUTHOR_NAME', 'Aybak'),
            gitAuthorEmail: $get('AYBAK_GIT_AUTHOR_EMAIL', 'aybak@localhost'),
            webhookUrl: $get('AYBAK_WEBHOOK_URL'),
            webhookKeyFile: $get('AYBAK_WEBHOOK_KEY_FILE'),
            dataplaneUrl: $get('AYBAK_DATAPLANE_URL'),
            dataplaneUser: $get('AYBAK_DATAPLANE_USER'),
            dataplanePasswordFile: $get('AYBAK_DATAPLANE_PASSWORD_FILE'),
            dataplaneBackends: $backends,
            dataplaneSendProxyV2: $get('AYBAK_DATAPLANE_SEND_PROXY_V2', 'no') === 'yes',
            // The certificate authorities the webhook, the Data Plane API and
            // git over HTTPS are checked against, for a server whose
            // certificate is from a private authority. When set, these alone
            // are trusted for those outputs, in place of the public
            // authorities in the image.
            caFile: $get('AYBAK_CA_FILE'),
        );
        $config->requireOutputSettings();

        return $config;
    }

    /**
     * How the git output reaches its repository, from the URL's scheme: 'ssh'
     * for ssh:// or user@host:path, 'https' for https://, null for anything
     * else.
     */
    public function gitTransport(): ?string
    {
        $url = (string) $this->gitUrl;
        if (str_starts_with($url, 'ssh://') || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:/', $url) === 1) {
            return 'ssh';
        }

        return str_starts_with($url, 'https://') ? 'https' : null;
    }

    private function requireOutputSettings(): void
    {
        $ssh = ['AYBAK_GIT_KEY_FILE' => $this->gitKeyFile, 'AYBAK_GIT_KNOWN_HOSTS_FILE' => $this->gitKnownHostsFile];
        $https = ['AYBAK_GIT_USER' => $this->gitUser, 'AYBAK_GIT_TOKEN_FILE' => $this->gitTokenFile];
        if (in_array('git', $this->outputs, true)) {
            if ($this->gitUrl === null) {
                throw new ConfigException('AYBAK_GIT_URL is required when AYBAK_OUTPUTS includes git.');
            }
            $transport = $this->gitTransport()
                ?? throw new ConfigException('AYBAK_GIT_URL must be an ssh:// or https:// URL, or user@host:path.');
            // A user or password in an https:// URL would put the credential in
            // git's arguments and in its error messages.
            if ($transport === 'https' && preg_match('#^https://[^/]*@#', (string) $this->gitUrl) === 1) {
                throw new ConfigException('AYBAK_GIT_URL must not carry a user or password; set AYBAK_GIT_USER and AYBAK_GIT_TOKEN_FILE.');
            }
            [$required, $other] = $transport === 'ssh' ? [$ssh, $https] : [$https, $ssh];
            foreach ($required as $key => $value) {
                if ($value === null) {
                    throw new ConfigException("$key is required when AYBAK_GIT_URL is an $transport URL.");
                }
            }
            // The user is handed to git's credential protocol, one field per
            // line, so it may hold no white space.
            if ($transport === 'https' && preg_match('/^[^\s:@\/]{1,100}$/', (string) $this->gitUser) !== 1) {
                throw new ConfigException('AYBAK_GIT_USER must be a user name without spaces, colons, slashes or @.');
            }
            // The other method's settings would be ignored; refusing them makes
            // a half-changed configuration visible at once.
            foreach ($other as $key => $value) {
                if ($value !== null) {
                    throw new ConfigException("$key does not apply when AYBAK_GIT_URL is an $transport URL.");
                }
            }
        }
        $need = [
            'git' => [],
            'webhook' => ['AYBAK_WEBHOOK_URL' => $this->webhookUrl, 'AYBAK_WEBHOOK_KEY_FILE' => $this->webhookKeyFile],
            'dataplane' => ['AYBAK_DATAPLANE_URL' => $this->dataplaneUrl, 'AYBAK_DATAPLANE_USER' => $this->dataplaneUser, 'AYBAK_DATAPLANE_PASSWORD_FILE' => $this->dataplanePasswordFile],
        ];
        foreach ($this->outputs as $output) {
            foreach ($need[$output] as $key => $value) {
                if ($value === null) {
                    throw new ConfigException("$key is required when AYBAK_OUTPUTS includes $output.");
                }
            }
        }
        if (in_array('webhook', $this->outputs, true) && !str_starts_with((string) $this->webhookUrl, 'https://')) {
            throw new ConfigException('AYBAK_WEBHOOK_URL must be an https:// URL, because the node set is signed but not encrypted.');
        }
        if (in_array('dataplane', $this->outputs, true) && $this->dataplaneBackends === []) {
            throw new ConfigException('AYBAK_DATAPLANE_BACKENDS is required when AYBAK_OUTPUTS includes dataplane.');
        }
    }
}
