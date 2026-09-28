<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Disk Metadata Model
 *
 * @package VirtualizorVpsCleaner\Models
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Models;

class Disk
{
    private int $did;
    private string $vpsUuid;
    private string $path;
    private string $size;
    private string $type;
    private bool $primary;
    private ?string $storage;

    public function __construct(array $data)
    {
        $this->did = (int) ($data['did'] ?? 0);
        $this->vpsUuid = (string) ($data['vps_uuid'] ?? '');
        $this->path = (string) ($data['path'] ?? '');
        $this->size = (string) ($data['size'] ?? '0');
        $this->type = (string) ($data['type'] ?? 'unknown');
        $this->primary = !empty($data['primary']);
        $this->storage = isset($data['storage']) ? (string) $data['storage'] : null;
    }

    public function getDid(): int
    {
        return $this->did;
    }

    public function getVpsUuid(): string
    {
        return $this->vpsUuid;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSize(): string
    {
        return $this->size;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function getStorage(): ?string
    {
        return $this->storage;
    }

    public function toArray(): array
    {
        return [
            'did'      => $this->did,
            'vps_uuid' => $this->vpsUuid,
            'path'     => $this->path,
            'size'     => $this->size,
            'type'     => $this->type,
            'primary'  => $this->primary,
            'storage'  => $this->storage,
        ];
    }
}
