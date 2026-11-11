<?php

declare(strict_types=1);

namespace Aybak;

/**
 * The nodes a load balancer should send traffic to, and the address of each.
 *
 * A node is left out when it is not Ready, when it is cordoned, when it has no
 * address of the configured kind, and, with the `workers` selection, when it
 * carries the control-plane role label. The set is sorted by node name, so two
 * reads of the same cluster always give the same bytes.
 */
final readonly class NodeSet
{
    private const string CONTROL_PLANE_LABEL = 'node-role.kubernetes.io/control-plane';
    private const string CALICO_ANNOTATION = 'projectcalico.org/IPv4Address';

    /**
     * @param list<array{name: string, address: string}> $nodes
     */
    private function __construct(public string $cluster, public array $nodes)
    {
    }

    /**
     * @param list<array<string, mixed>> $items the `items` of a NodeList
     */
    public static function fromNodeList(string $cluster, array $items, string $selection, string $source): self
    {
        $nodes = [];
        foreach ($items as $node) {
            $name = $node['metadata']['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            if ($selection === 'workers' && array_key_exists(self::CONTROL_PLANE_LABEL, $node['metadata']['labels'] ?? [])) {
                continue;
            }
            if (($node['spec']['unschedulable'] ?? false) === true) {
                continue;
            }
            if (!self::isReady($node)) {
                continue;
            }
            $address = self::address($node, $source);
            if ($address === null) {
                continue;
            }
            $nodes[$name] = ['name' => $name, 'address' => $address];
        }
        ksort($nodes, SORT_STRING);

        return new self($cluster, array_values($nodes));
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** The bytes every output carries, apart from the time it was read. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->nodes, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{cluster: string, generated: string, nodes: list<array{name: string, address: string}>}
     */
    public function toArray(\DateTimeImmutable $at): array
    {
        return [
            'cluster' => $this->cluster,
            'generated' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'nodes' => $this->nodes,
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isReady(array $node): bool
    {
        foreach ($node['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? null) === 'Ready') {
                return ($condition['status'] ?? null) === 'True';
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function address(array $node, string $source): ?string
    {
        if ($source === 'calico') {
            $value = $node['metadata']['annotations'][self::CALICO_ANNOTATION] ?? null;
            if (!is_string($value)) {
                return null;
            }
            $ip = explode('/', $value, 2)[0];

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
        }
        foreach ($node['status']['addresses'] ?? [] as $a) {
            if (($a['type'] ?? null) === $source && is_string($a['address'] ?? null)
                && filter_var($a['address'], FILTER_VALIDATE_IP) !== false) {
                return $a['address'];
            }
        }

        return null;
    }
}
