<p align="center">
<img src=".mamluk/logo-horizontal.svg" alt="Aybak by Mamluk" title="Aybak by Mamluk" width="377"/>
</p>

# Aybak by Mamluk

Aybak keeps the load balancer in front of a Kubernetes cluster in step with the
cluster's nodes. It runs inside the cluster, reads the nodes through a
read-only ServiceAccount, and sends the addresses of the qualifying nodes to
one or more outputs:

* **git**: a JSON file in a git repository, for a pipeline that renders and
  deploys the load balancer's configuration;
* **webhook**: a signed HTTPS POST, for any receiver;
* **dataplane**: HAProxy's Data Plane API (v3), which replaces the servers of
  named backends in one transaction.

It is intended for clusters that have no cloud load balancer, or whose
operators prefer to run their own, and it needs no cloud controller manager.

Aybak is an optional component of [BKE](https://bke.maml.uk), the Bahriya
Kubernetes Engine, and can also be run on its own.

## The safety rule

**A failed or empty read sends nothing.** If the Kubernetes API cannot be read,
or no node qualifies, every output keeps the last set it received. A load
balancer emptied while the API server was briefly unreachable would take the
whole cluster offline.

## How it works

Every `AYBAK_RESYNC_SECONDS` (15 by default), Aybak lists the nodes and selects:

* worker nodes only (`AYBAK_NODES=workers`, the default), or every node (`all`);
* nodes that are Ready and schedulable;
* one address per node, from the source named by `AYBAK_ADDRESS`.

The set is sent to an output only when it differs from the last set that output
accepted. An output that fails is tried again on the next pass, and does not
hold back the others. Aybak lists every node on every pass rather than watching
for changes, so a missed event cannot leave a stale set in place for longer
than one interval.

A node set looks like this, with nodes sorted by name:

```json
{
    "cluster": "example",
    "generated": "2026-11-11T10:00:00Z",
    "nodes": [
        {"name": "w1", "address": "10.1.0.4"},
        {"name": "w2", "address": "10.1.0.6"}
    ]
}
```

## Running Aybak

### Image

```
1x.ax/bke/aybak:<version>
```

The image is built on Mamluk's public PHP 8.5 CLI image and runs as UID 10001.
There is no `latest` tag, so pin a full version.

Each image is signed with the BKE image signing key. To verify one:

```
curl -fsSO https://bke.maml.uk/signing/bke-images-2026.pub
cosign verify --key bke-images-2026.pub --insecure-ignore-tlog=true \
  1x.ax/bke/aybak:<version>
```

The signatures are not recorded in a public transparency log, hence
`--insecure-ignore-tlog`.

### On Kubernetes

BKE installs and configures Aybak itself. On any other cluster, Aybak needs:

* a ServiceAccount bound to a ClusterRole that allows `get` and `list` on nodes,
  and nothing else;
* one replica (a second replica does no harm, but sends every change twice);
* a writable `emptyDir` at `AYBAK_STATE_DIR` and another at `/tmp`, so that the
  root filesystem can be read-only;
* the credential files of the outputs it uses, mounted from Secrets.

The container's security context can be fully restricted:

```yaml
securityContext:
  runAsNonRoot: true
  runAsUser: 10001
  runAsGroup: 10001
  allowPrivilegeEscalation: false
  readOnlyRootFilesystem: true
  capabilities:
    drop: ["ALL"]
  seccompProfile:
    type: RuntimeDefault
```

### Health and logs

Aybak writes two files under `$AYBAK_STATE_DIR/status`, each holding the Unix
time at which it was written:

* `alive`, on every pass;
* `ready`, only when the last read succeeded and every output holds the current
  set. It is removed otherwise.

These are the probes BKE uses, with the default state directory:

```yaml
livenessProbe:
  exec:
    command: ["sh", "-c", "test $(($(date +%s) - $(cat /var/lib/aybak/status/alive))) -lt 600"]
  initialDelaySeconds: 30
  periodSeconds: 60
  failureThreshold: 3
readinessProbe:
  exec:
    command: ["test", "-f", "/var/lib/aybak/status/ready"]
  periodSeconds: 10
```

Logs are written to standard output, one JSON object per line.

## Settings

Aybak is configured entirely by environment variables. **Every credential is
read from a file named by a setting**, never from the setting itself, so no
secret appears in the process environment or in a log line. Mount Secrets as
files.

### General

| Variable | Default | Description |
|---|---|---|
| `AYBAK_CLUSTER` | required | The cluster's name, written into every node set. Lower-case letters, digits, dots and hyphens. |
| `AYBAK_OUTPUTS` | required | A comma-separated list of `git`, `webhook` and `dataplane`. |
| `AYBAK_NODES` | `workers` | `workers` excludes nodes labelled `node-role.kubernetes.io/control-plane`; `all` includes them. |
| `AYBAK_ADDRESS` | `InternalIP` | `InternalIP` or `ExternalIP` from the node's status, or `calico` for the `projectcalico.org/IPv4Address` annotation. |
| `AYBAK_RESYNC_SECONDS` | `15` | Seconds between passes, from 5 to 300. |
| `AYBAK_STATE_DIR` | `/var/lib/aybak` | A writable directory for the git working copy and the status files. |
| `AYBAK_CA_FILE` | none | PEM certificate authorities for the webhook, the Data Plane API and git over HTTPS, for a server whose certificate is issued by a private authority. When set, only these are trusted for those outputs, in place of the public authorities in the image. |

Aybak finds the Kubernetes API from the in-cluster environment
(`KUBERNETES_SERVICE_HOST`, the ServiceAccount token and CA). The token is read
again on every pass, so a rotated token is picked up without a restart.

### git

Aybak reaches the repository over SSH or HTTPS, chosen by the URL. Set the
two settings of one method; setting any of the other method's is refused.

| Variable | Default | Description |
|---|---|---|
| `AYBAK_GIT_URL` | required | The repository's URL: `ssh://` or `user@host:path` for SSH, `https://` for HTTPS. An HTTPS URL may not carry a user or password. |
| `AYBAK_GIT_KEY_FILE` | SSH: required | A private key with write access to the repository. |
| `AYBAK_GIT_KNOWN_HOSTS_FILE` | SSH: required | known_hosts for the git server. Host keys are never learned on first contact. |
| `AYBAK_GIT_USER` | HTTPS: required | The user the token belongs to. |
| `AYBAK_GIT_TOKEN_FILE` | HTTPS: required | An access token, or password, with write access to the repository. |
| `AYBAK_GIT_BRANCH` | `master` | The branch to commit to. |
| `AYBAK_GIT_PATH` | `nodes/<cluster>.json` | The file's path in the repository. |
| `AYBAK_GIT_AUTHOR_NAME` | `Aybak` | The commit author's name. |
| `AYBAK_GIT_AUTHOR_EMAIL` | `aybak@localhost` | The commit author's email address. |

Over HTTPS, git asks a credential helper for the user and token, and the
helper reads the token from its file at that moment. The token is never in a
process argument, the environment or a log line, and nothing stores it. The
server's certificate is checked against the image's public authorities, or
against `AYBAK_CA_FILE` when it is set.

A commit is made only when the nodes differ from those in the file, so a
restarted Aybak does not commit the same nodes again.

### webhook

| Variable | Default | Description |
|---|---|---|
| `AYBAK_WEBHOOK_URL` | required | An `https://` URL. |
| `AYBAK_WEBHOOK_KEY_FILE` | required | The shared key for the signature. |

The header `X-Aybak-Signature: sha256=<hex>` carries the HMAC-SHA256 of the
exact request body, keyed with the shared key. A receiver should compute the
same value and compare the two in constant time before acting on the set.

### dataplane

| Variable | Default | Description |
|---|---|---|
| `AYBAK_DATAPLANE_URL` | required | The Data Plane API's base URL. |
| `AYBAK_DATAPLANE_USER` | required | The API user. |
| `AYBAK_DATAPLANE_PASSWORD_FILE` | required | The API password. |
| `AYBAK_DATAPLANE_BACKENDS` | required | A comma-separated list of `<backend>:<port>`, for example `http:30080,https:30443`. |
| `AYBAK_DATAPLANE_SEND_PROXY_V2` | `no` | `yes` adds `send-proxy-v2` to every server. |

**A backend named here belongs to Aybak.** Every server in it is replaced,
including any added by hand. All named backends change in one transaction, so
HAProxy sees either the old servers in every backend or the new ones in every
backend.

## Versions

Each version is a git tag in this repository and an image tag of the same
name, `1x.ax/bke/aybak:<version>`. Versions follow semantic versioning: a patch
version fixes or adds without changing a setting's meaning, and a minor version
adds a capability.

| Version | Adds |
| --- | --- |
| 1.1.0 | The git output over HTTPS with a token, as well as over SSH. |
| 1.0.1 | `AYBAK_CA_FILE`, for a server whose certificate is issued by a private certificate authority. |
| 1.0.0 | The first version: the git, webhook and dataplane outputs, and the safety rule. |

BKE pins the Aybak version it installs in each BKE release.

## Development

Development needs PHP 8.5 with the curl and pcntl extensions, and git for the
git output's tests. The HTTPS tests run a git server on the loopback interface,
with PHP's built-in web server in front of `git http-backend`.

```
composer install
vendor/bin/phpunit
```

## The name

Sultan Izz al-Din Aybak was the first Sultan of the Mamluk Sultanate of Cairo,
from 1250 to 1257.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
