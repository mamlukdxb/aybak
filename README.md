<p align="center">
<img src=".mamluk/logo-horizontal.svg" alt="Aybak by Mamluk" title="Aybak by Mamluk" width="377"/>
</p>

# Aybak by Mamluk

Aybak keeps the load balancer in front of a Kubernetes cluster in step with the
cluster's nodes. It runs inside the cluster, reads the nodes with a read-only
ServiceAccount, and sends the addresses of the qualifying nodes to one or more
outputs:

* **git**: a JSON file in a git repository, for a pipeline that renders and
  deploys the load balancer's configuration;
* **webhook**: a signed HTTPS POST, for any receiver;
* **dataplane**: HAProxy's Data Plane API (v3), which replaces the servers of
  named backends in one transaction.

It is intended for clusters without a cloud load balancer, or whose operators
prefer to run their own: a load balancer that knows the nodes, without a cloud
controller manager.

Aybak is an optional component of BKE, the Bahriya Kubernetes Engine, and is
also usable on its own.

## The safety rule

**A failed or empty read sends nothing.** If the Kubernetes API cannot be read,
or no node qualifies, every output keeps the last set it received. A load
balancer emptied because the API server was briefly unreachable would take the
whole cluster offline; aybak never does that.

## How it works

Every `AYBAK_RESYNC_SECONDS` (15 by default), aybak lists the nodes and selects:

* worker nodes only (`AYBAK_NODES=workers`, the default), or every node (`all`);
* nodes that are Ready and schedulable;
* one address per node, from the source named by `AYBAK_ADDRESS`.

The set is sent to an output only when it differs from the last set that output
accepted. An output that fails is tried again on the next pass, without holding
back the others. A full list on every pass, rather than a watch, means a missed
event can never leave a stale set in place for longer than one interval.

The node set is:

```json
{
    "cluster": "beyazid",
    "generated": "2026-11-11T10:00:00Z",
    "nodes": [
        {"name": "w1", "address": "10.1.0.4"},
        {"name": "w2", "address": "10.1.0.6"}
    ]
}
```

Nodes are sorted by name.

## Image

```
1x.ax/bke/aybak:<version>
```

The image is built on `1x.ax/mamluk/php/8.5:cli` and signed with the BKE
signing key. It runs as UID 10001. There is no `latest` tag: pin a full
version.

## Settings

Aybak is configured entirely by environment variables. **Every credential is
read from a file named by a setting**, never from the setting itself, so no
secret is ever in the process environment or a log line. Mount Secrets as
files.

### General

| Variable | Default | Description |
|---|---|---|
| `AYBAK_CLUSTER` | required | The cluster's name, written into every node set. Lower-case letters, digits, dots and hyphens. |
| `AYBAK_OUTPUTS` | required | A comma-separated list of `git`, `webhook`, `dataplane`. |
| `AYBAK_NODES` | `workers` | `workers` excludes nodes labelled `node-role.kubernetes.io/control-plane`; `all` includes them. |
| `AYBAK_ADDRESS` | `InternalIP` | `InternalIP` or `ExternalIP` from the node's status, or `calico` for the `projectcalico.org/IPv4Address` annotation. |
| `AYBAK_RESYNC_SECONDS` | `15` | Seconds between passes, from 5 to 300. |
| `AYBAK_STATE_DIR` | `/var/lib/aybak` | Writable directory for the git working copy and the status files. |
| `AYBAK_CA_FILE` | none | PEM certificate authorities for the webhook and the Data Plane API, for a load balancer whose certificate is from a private authority. When set, only these are trusted for those two outputs, in place of the public authorities in the image. |

The Kubernetes API is found from the in-cluster environment
(`KUBERNETES_SERVICE_HOST`, the ServiceAccount token and CA). The token is
re-read on every pass, so a rotated token is picked up without a restart.

### git

| Variable | Default | Description |
|---|---|---|
| `AYBAK_GIT_URL` | required | SSH URL of the repository. |
| `AYBAK_GIT_KEY_FILE` | required | Private key with write access to the repository. |
| `AYBAK_GIT_KNOWN_HOSTS_FILE` | required | known_hosts for the git server. Host keys are never learned on first contact. |
| `AYBAK_GIT_BRANCH` | `master` | Branch to commit to. |
| `AYBAK_GIT_PATH` | `nodes/<cluster>.json` | Path of the file in the repository. |
| `AYBAK_GIT_AUTHOR_NAME` | `Aybak` | Commit author name. |
| `AYBAK_GIT_AUTHOR_EMAIL` | `aybak@localhost` | Commit author email. |

A commit is made only when the nodes differ from those in the file, so a
restarted aybak does not commit the same nodes again.

### webhook

| Variable | Default | Description |
|---|---|---|
| `AYBAK_WEBHOOK_URL` | required | An `https://` URL. |
| `AYBAK_WEBHOOK_KEY_FILE` | required | Shared key for the signature. |

The header `X-Aybak-Signature: sha256=<hex>` carries the HMAC-SHA256 of the
exact request body with the shared key. A receiver should compute the same
value and compare in constant time before acting on the set.

### dataplane

| Variable | Default | Description |
|---|---|---|
| `AYBAK_DATAPLANE_URL` | required | Base URL of the Data Plane API. |
| `AYBAK_DATAPLANE_USER` | required | API user. |
| `AYBAK_DATAPLANE_PASSWORD_FILE` | required | API password. |
| `AYBAK_DATAPLANE_BACKENDS` | required | Comma-separated `<backend>:<port>`, e.g. `http:30080,https:30443`. |
| `AYBAK_DATAPLANE_SEND_PROXY_V2` | `no` | `yes` adds `send-proxy-v2` to every server. |

**A backend named here belongs to aybak.** Every server in it is replaced,
including any added by hand. All named backends change in one transaction:
HAProxy sees either the old servers everywhere or the new ones everywhere.

## Health

Aybak writes two files under `$AYBAK_STATE_DIR/status`:

* `alive`, on every pass;
* `ready`, only when the last read succeeded and every output holds the current
  set. It is removed otherwise.

Use them for the liveness and readiness probes. Logs are one JSON object per
line on standard output.

## Running it on Kubernetes

BKE installs aybak for you. On another cluster, it needs:

* a ServiceAccount bound to a ClusterRole that may `get` and `list` nodes, and
  nothing else;
* one replica (two replicas would be harmless, but send every change twice);
* a writable `emptyDir` at `AYBAK_STATE_DIR` and another at `/tmp`, so the
  root filesystem can be read-only;
* the credential files of the outputs it uses, mounted from Secrets.

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

## Development

```
composer install
vendor/bin/phpunit
```

PHP 8.5 with the curl and pcntl extensions, and git for the git tests.

## The name

Sultan Izz al-Din Aybak was the first Sultan of the Mamluk Sultanate of Cairo,
from 1250 to 1257.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
