<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * VPS Query, Search, Pagination and Inspection Service
 *
 * @package VirtualizorVpsCleaner\Services
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Services;

use PDO;
use VirtualizorVpsCleaner\Database\Connection;
use VirtualizorVpsCleaner\Database\SchemaInspector;
use VirtualizorVpsCleaner\Models\Vps;
use VirtualizorVpsCleaner\Models\Disk;
use VirtualizorVpsCleaner\Models\IpAddress;
use VirtualizorVpsCleaner\Models\Server;
use VirtualizorVpsCleaner\Models\Task;

class VpsService
{
    private Connection $connection;
    private SchemaInspector $inspector;

    public function __construct(Connection $connection, SchemaInspector $inspector)
    {
        $this->connection = $connection;
        $this->inspector = $inspector;
    }

    /**
     * Find a VPS by its database ID with all relations hydrated
     *
     * @param int $vpsid
     * @return Vps|null
     */
    public function findById(int $vpsid): ?Vps
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM `vps` WHERE `vpsid` = :vpsid LIMIT 1");
        $stmt->execute([':vpsid' => $vpsid]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $vps = new Vps($row);
        $this->hydrateRelations($vps);

        return $vps;
    }

    /**
     * Get paginated and filtered list of VPS instances
     *
     * @param int $page 1-indexed
     * @param int $limit
     * @param string|null $query Search term for ID, name, hostname, or IP
     * @param int|null $serverFilter Optional filter by server ID
     * @return array{items: array<Vps>, total: int, page: int, limit: int, totalPages: int}
     */
    public function getPagedList(int $page = 1, int $limit = 15, ?string $query = null, ?int $serverFilter = null): array
    {
        $pdo = $this->connection->getPdo();
        $whereClauses = [];
        $params = [];

        // Server filter
        if ($serverFilter !== null) {
            $whereClauses[] = "`serid` = :serid";
            $params[':serid'] = $serverFilter;
        }

        // Query search
        if (!empty($query)) {
            $subWhere = [];

            // If numeric, match vpsid
            if (is_numeric($query)) {
                $subWhere[] = "`vpsid` = :query_id";
                $params[':query_id'] = (int) $query;
            }

            // Match vps_name, hostname, uuid
            $subWhere[] = "`vps_name` LIKE :query_like";
            $subWhere[] = "`hostname` LIKE :query_like";
            $subWhere[] = "`uuid` LIKE :query_like";
            $params[':query_like'] = "%{$query}%";

            // If query could be an IP, match ips table
            if (filter_var($query, FILTER_VALIDATE_IP) || str_contains($query, '.')) {
                $subWhere[] = "`vpsid` IN (SELECT `vpsid` FROM `ips` WHERE `ip` LIKE :query_ip)";
                $params[':query_ip'] = "%{$query}%";
            }

            $whereClauses[] = "(" . implode(' OR ', $subWhere) . ")";
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

        // Total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `vps` {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $limit));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $limit;

        // Fetch records
        $sql = "SELECT * FROM `vps` {$whereSql} ORDER BY `vpsid` ASC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $vps = new Vps($row);
            $this->hydrateRelations($vps);
            $items[] = $vps;
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Search VPS records
     *
     * @param string $query
     * @param int $limit
     * @return array<Vps>
     */
    public function search(string $query, int $limit = 50): array
    {
        $result = $this->getPagedList(1, $limit, $query);
        return $result['items'];
    }

    /**
     * Hydrate disks, IPs, server, and tasks for a VPS entity
     */
    public function hydrateRelations(Vps $vps): void
    {
        $pdo = $this->connection->getPdo();

        // 1. Hydrate Disks
        if ($this->inspector->hasTable('disks') && $vps->getUuid() !== '') {
            $stmt = $pdo->prepare("SELECT * FROM `disks` WHERE `vps_uuid` = :uuid ORDER BY `did` ASC");
            $stmt->execute([':uuid' => $vps->getUuid()]);
            $disks = [];
            while ($row = $stmt->fetch()) {
                $disks[] = new Disk($row);
            }
            $vps->setDisks($disks);
        }

        // 2. Hydrate IPs
        if ($this->inspector->hasTable('ips')) {
            $stmt = $pdo->prepare("SELECT * FROM `ips` WHERE `vpsid` = :vpsid ORDER BY `ipid` ASC");
            $stmt->execute([':vpsid' => $vps->getVpsid()]);
            $ips = [];
            while ($row = $stmt->fetch()) {
                $ips[] = new IpAddress($row);
            }
            $vps->setIps($ips);
        }

        // 3. Hydrate Server
        if ($this->inspector->hasTable('servers')) {
            $stmt = $pdo->prepare("SELECT * FROM `servers` WHERE `serid` = :serid LIMIT 1");
            $stmt->execute([':serid' => $vps->getSerid()]);
            $row = $stmt->fetch();
            if ($row) {
                $vps->setServer(new Server($row));
            } else {
                $vps->setServer(new Server(['serid' => $vps->getSerid(), 'server_name' => "Node #{$vps->getSerid()} (Deleted Node)"]));
            }
        }

        // 4. Hydrate Tasks
        if ($this->inspector->hasTable('tasks')) {
            $stmt = $pdo->prepare("SELECT * FROM `tasks` WHERE `vpsid` = :vpsid ORDER BY `taskid` DESC LIMIT 10");
            $stmt->execute([':vpsid' => $vps->getVpsid()]);
            $tasks = [];
            while ($row = $stmt->fetch()) {
                $tasks[] = new Task($row);
            }
            $vps->setTasks($tasks);
        }
    }

    /**
     * Get summary counts of servers and VPS
     *
     * @return array{totalVps: int, totalServers: int, totalIps: int}
     */
    public function getSystemSummary(): array
    {
        $pdo = $this->connection->getPdo();

        $vpsCount = (int) $pdo->query("SELECT COUNT(*) FROM `vps`")->fetchColumn();
        $serversCount = $this->inspector->hasTable('servers') ? (int) $pdo->query("SELECT COUNT(*) FROM `servers`")->fetchColumn() : 0;
        $ipsCount = $this->inspector->hasTable('ips') ? (int) $pdo->query("SELECT COUNT(*) FROM `ips` WHERE `vpsid` > 0")->fetchColumn() : 0;

        return [
            'totalVps'     => $vpsCount,
            'totalServers' => $serversCount,
            'totalIps'     => $ipsCount,
        ];
    }
}
