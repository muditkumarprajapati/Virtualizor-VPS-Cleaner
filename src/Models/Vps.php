<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * VPS Entity Model
 *
 * @package VirtualizorVpsCleaner\Models
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Models;

class Vps
{
    private int $vpsid;
    private string $vpsName;
    private string $uuid;
    private int $serid;
    private int $uid;
    private string $hostname;
    private string $osName;
    private int $ram;
    private int $cores;
    private int $space;
    private string $status;

    /**
     * @var array<Disk>
     */
    private array $disks = [];

    /**
     * @var array<IpAddress>
     */
    private array $ips = [];

    private ?Server $server = null;

    /**
     * @var array<Task>
     */
    private array $tasks = [];

    public function __construct(array $data)
    {
        $this->vpsid = (int) ($data['vpsid'] ?? 0);
        $this->vpsName = (string) ($data['vps_name'] ?? '');
        $this->uuid = (string) ($data['uuid'] ?? '');
        $this->serid = (int) ($data['serid'] ?? 0);
        $this->uid = (int) ($data['uid'] ?? 0);
        $this->hostname = (string) ($data['hostname'] ?? '');
        $this->osName = (string) ($data['os_name'] ?? $data['osname'] ?? 'Unknown');
        $this->ram = (int) ($data['ram'] ?? 0);
        $this->cores = (int) ($data['cores'] ?? 1);
        $this->space = (int) ($data['space'] ?? 0);
        $this->status = (string) ($data['status'] ?? 'unknown');
    }

    public function getVpsid(): int
    {
        return $this->vpsid;
    }

    public function getVpsName(): string
    {
        return $this->vpsName;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getSerid(): int
    {
        return $this->serid;
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getOsName(): string
    {
        return $this->osName;
    }

    public function getRam(): int
    {
        return $this->ram;
    }

    public function getCores(): int
    {
        return $this->cores;
    }

    public function getSpace(): int
    {
        return $this->space;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<Disk>
     */
    public function getDisks(): array
    {
        return $this->disks;
    }

    /**
     * @param array<Disk> $disks
     */
    public function setDisks(array $disks): self
    {
        $this->disks = $disks;
        return $this;
    }

    /**
     * @return array<IpAddress>
     */
    public function getIps(): array
    {
        return $this->ips;
    }

    /**
     * @param array<IpAddress> $ips
     */
    public function setIps(array $ips): self
    {
        $this->ips = $ips;
        return $this;
    }

    public function getServer(): ?Server
    {
        return $this->server;
    }

    public function setServer(?Server $server): self
    {
        $this->server = $server;
        return $this;
    }

    /**
     * @return array<Task>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * @param array<Task> $tasks
     */
    public function setTasks(array $tasks): self
    {
        $this->tasks = $tasks;
        return $this;
    }

    /**
     * Has any pending tasks?
     */
    public function hasPendingTasks(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->isPending()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get primary IPv4 address or first assigned IP
     */
    public function getPrimaryIp(): string
    {
        if (empty($this->ips)) {
            return 'None';
        }
        return $this->ips[0]->getIp();
    }

    /**
     * Get all IPv4 and IPv6 addresses as a comma-separated list
     */
    public function getAllIpsFormatted(): string
    {
        if (empty($this->ips)) {
            return 'None';
        }

        $list = [];
        foreach ($this->ips as $ipObj) {
            $ip = $ipObj->getIp();
            if (!empty($ip)) {
                $list[] = $ip;
            }
            $ipv6 = $ipObj->getIpv6();
            if (!empty($ipv6)) {
                $list[] = $ipv6;
            }
        }

        return implode(', ', $list);
    }

    /**
     * Get disk summary (e.g. "1 disk(s) - 50GB [qcow2]")
     */
    public function getDiskSummary(): string
    {
        if (empty($this->disks)) {
            return '0 disks';
        }

        $count = count($this->disks);
        $types = array_unique(array_map(fn($d) => $d->getType(), $this->disks));
        return "{$count} disk(s) [" . implode(', ', $types) . "]";
    }

    public function toArray(): array
    {
        return [
            'vpsid'      => $this->vpsid,
            'vps_name'   => $this->vpsName,
            'uuid'       => $this->uuid,
            'serid'      => $this->serid,
            'uid'        => $this->uid,
            'hostname'   => $this->hostname,
            'os_name'    => $this->osName,
            'ram'        => $this->ram,
            'cores'      => $this->cores,
            'space'      => $this->space,
            'status'     => $this->status,
            'server'     => ($this->server !== null) ? $this->server->toArray() : null,
            'disks'      => array_map(fn($d) => $d->toArray(), $this->disks),
            'ips'        => array_map(fn($i) => $i->toArray(), $this->ips),
            'tasks'      => array_map(fn($t) => $t->toArray(), $this->tasks),
        ];
    }
}
