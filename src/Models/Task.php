<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Virtualizor Task / Action Model
 *
 * @package VirtualizorVpsCleaner\Models
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Models;

class Task
{
    private int $taskId;
    private int $vpsId;
    private string $action;
    private int $status;
    private int $time;
    private ?string $data;

    public function __construct(array $data)
    {
        $this->taskId = (int) ($data['taskid'] ?? $data['actid'] ?? 0);
        $this->vpsId = (int) ($data['vpsid'] ?? 0);
        $this->action = (string) ($data['action'] ?? 'unknown');
        $this->status = (int) ($data['status'] ?? 0);
        $this->time = (int) ($data['time'] ?? time());
        $this->data = isset($data['data']) ? (string) $data['data'] : null;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function getVpsId(): int
    {
        return $this->vpsId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        // In Virtualizor, task status 0 is pending/running
        return $this->status === 0;
    }

    public function getTime(): int
    {
        return $this->time;
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return [
            'taskid' => $this->taskId,
            'vpsid'  => $this->vpsId,
            'action' => $this->action,
            'status' => $this->status,
            'time'   => $this->time,
            'data'   => $this->data,
        ];
    }
}
