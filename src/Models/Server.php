<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Server Node Model
 *
 * @package VirtualizorVpsCleaner\Models
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Models;

class Server
{
    private int $serid;
    private string $serverName;
    private string $ip;
    private int $status;

    public function __construct(array $data)
    {
        $this->serid = (int) ($data['serid'] ?? 0);
        $this->serverName = (string) ($data['server_name'] ?? ($this->serid === 0 ? 'Master Server' : 'Server #' . $this->serid));
        $this->ip = (string) ($data['ip'] ?? '');
        $this->status = (int) ($data['status'] ?? 0);
    }

    public function getSerid(): int
    {
        return $this->serid;
    }

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function isOnline(): bool
    {
        return $this->status === 1;
    }

    public function toArray(): array
    {
        return [
            'serid'       => $this->serid,
            'server_name' => $this->serverName,
            'ip'          => $this->ip,
            'status'      => $this->status,
        ];
    }
}
