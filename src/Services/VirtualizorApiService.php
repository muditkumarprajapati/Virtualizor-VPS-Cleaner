<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Virtualizor API and Slave Connectivity Inspector
 *
 * @package VirtualizorVpsCleaner\Services
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Services;

use VirtualizorVpsCleaner\Models\Vps;
use VirtualizorVpsCleaner\Models\Server;
use VirtualizorVpsCleaner\Logging\Logger;

class VirtualizorApiService
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Investigate if native Virtualizor API can delete the VPS or why it fails
     *
     * @param Vps $vps
     * @return array{canUseApi: bool, reason: string, details: array<string>}
     */
    public function investigateApiDeletion(Vps $vps): array
    {
        $server = $vps->getServer();
        $serid = $vps->getSerid();

        // If it's on Master (serid = 0)
        if ($serid === 0) {
            return [
                'canUseApi' => false,
                'reason'    => 'VPS is assigned to Master server (serid 0). Standard UI deletion should be used if active.',
                'details'   => [
                    'Node: Master Server (serid 0)',
                    'Action: Verify if this is an orphaned test or ghost record.',
                ],
            ];
        }

        // If slave server is not found in servers table
        if ($server === null) {
            return [
                'canUseApi' => false,
                'reason'    => "Slave server (serid {$serid}) no longer exists in the 'servers' table.",
                'details'   => [
                    'The slave node record was already removed or never registered properly.',
                    'Virtualizor API cannot dispatch deletion commands without a valid server node.',
                    'Manual database cleanup is the only viable path to clear this orphaned record.',
                ],
            ];
        }

        // If slave server is marked offline in DB
        if (!$server->isOnline()) {
            return [
                'canUseApi' => false,
                'reason'    => "Slave server '{$server->getServerName()}' (ID {$serid}) is marked OFFLINE (status: 0).",
                'details'   => [
                    "Slave IP: {$server->getIp()}",
                    'Virtualizor control panel fails to delete VPS because it cannot reach the slave daemon.',
                    'Direct database cleanup is required to prune orphaned records.',
                ],
            ];
        }

        // Check if server IP responds to socket connection on Virtualizor daemon port (4083 / 4085)
        $isReachable = $this->testSlavePort($server->getIp(), 4083, 1.5);
        if (!$isReachable) {
            return [
                'canUseApi' => false,
                'reason'    => "Slave server '{$server->getServerName()}' ({$server->getIp()}:4083) is unreachable over the network.",
                'details'   => [
                    'Network connection to slave port 4083 timed out or was refused.',
                    'The hardware node has likely been retired, wiped, or reinstalled.',
                    'Virtualizor control panel throws a connection timeout error when attempting deletion.',
                    'Direct database cleanup is safe and required.',
                ],
            ];
        }

        // If slave is reachable, warn user that the server is alive!
        return [
            'canUseApi' => true,
            'reason'    => "Slave server '{$server->getServerName()}' appears reachable over port 4083.",
            'details'   => [
                'WARNING: The slave server node responded on port 4083.',
                'If this is an active customer node or newly reinstalled server reusing the IP, DO NOT DELETE!',
                'If the server was reinstalled and Virtualizor re-added with the same ID/IP, verify customer records first.',
            ],
        ];
    }

    /**
     * Test TCP connectivity to slave port
     */
    private function testSlavePort(string $ip, int $port = 4083, float $timeout = 1.0): bool
    {
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($fp !== false) {
            fclose($fp);
            return true;
        }

        return false;
    }
}
