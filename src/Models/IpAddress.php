<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * IP Address Metadata Model
 *
 * @package VirtualizorVpsCleaner\Models
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Models;

class IpAddress
{
    private int $ipid;
    private int $vpsid;
    private string $ip;
    private ?string $ipv6;
    private ?string $mac;
    private ?string $netmask;
    private ?string $gateway;
    private int $serid;
    private int $locked;

    public function __construct(array $data)
    {
        $this->ipid = (int) ($data['ipid'] ?? 0);
        $this->vpsid = (int) ($data['vpsid'] ?? 0);
        $this->ip = (string) ($data['ip'] ?? '');
        $this->ipv6 = !empty($data['ipv6']) ? (string) $data['ipv6'] : null;
        $this->mac = !empty($data['mac']) ? (string) $data['mac'] : null;
        $this->netmask = !empty($data['netmask']) ? (string) $data['netmask'] : null;
        $this->gateway = !empty($data['gateway']) ? (string) $data['gateway'] : null;
        $this->serid = (int) ($data['serid'] ?? 0);
        $this->locked = (int) ($data['locked'] ?? 1);
    }

    public function getIpid(): int
    {
        return $this->ipid;
    }

    public function getVpsid(): int
    {
        return $this->vpsid;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getIpv6(): ?string
    {
        return $this->ipv6;
    }

    public function getMac(): ?string
    {
        return $this->mac;
    }

    public function getNetmask(): ?string
    {
        return $this->netmask;
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function getSerid(): int
    {
        return $this->serid;
    }

    public function isLocked(): bool
    {
        return $this->locked === 1;
    }

    public function toArray(): array
    {
        return [
            'ipid'    => $this->ipid,
            'vpsid'   => $this->vpsid,
            'ip'      => $this->ip,
            'ipv6'    => $this->ipv6,
            'mac'     => $this->mac,
            'netmask' => $this->netmask,
            'gateway' => $this->gateway,
            'serid'   => $this->serid,
            'locked'  => $this->locked,
        ];
    }
}
