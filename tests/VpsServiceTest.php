<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * VPS Service Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use VirtualizorVpsCleaner\Services\VpsService;

class VpsServiceTest extends TestCase
{
    private VpsService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new VpsService($this->connection, $this->inspector);
    }

    public function testFindById(): void
    {
        $vps = $this->service->findById(101);
        $this->assertNotNull($vps);
        $this->assertEquals(101, $vps->getVpsid());
        $this->assertEquals('v1001', $vps->getVpsName());
        $this->assertEquals('uuid-orphaned-101', $vps->getUuid());
        $this->assertEquals(10, $vps->getSerid());
        $this->assertEquals('old-vm1.example.com', $vps->getHostname());

        // Hydrated relations
        $this->assertCount(2, $vps->getDisks());
        $this->assertCount(1, $vps->getIps());
        $this->assertNotNull($vps->getServer());
        $this->assertEquals('Retired-Dedicated-Node-A', $vps->getServer()->getServerName());
    }

    public function testFindByIdReturnsNullForNonExistentVps(): void
    {
        $vps = $this->service->findById(99999);
        $this->assertNull($vps);
    }

    public function testGetPagedList(): void
    {
        $result = $this->service->getPagedList(1, 2);
        $this->assertEquals(4, $result['total']);
        $this->assertEquals(2, $result['totalPages']);
        $this->assertCount(2, $result['items']);
    }

    public function testSearchByHostname(): void
    {
        $results = $this->service->search('live-client');
        $this->assertCount(1, $results);
        $this->assertEquals(102, $results[0]->getVpsid());
    }

    public function testSearchByIp(): void
    {
        $results = $this->service->search('198.51.100.101');
        $this->assertCount(1, $results);
        $this->assertEquals(101, $results[0]->getVpsid());
    }

    public function testVpsWithMissingDisksAndIpsHandledGracefully(): void
    {
        $vps = $this->service->findById(103);
        $this->assertNotNull($vps);
        $this->assertCount(0, $vps->getDisks());
        $this->assertCount(0, $vps->getIps());
        $this->assertEquals('None', $vps->getPrimaryIp());
        $this->assertEquals('0 disks', $vps->getDiskSummary());
    }

    public function testCreationDateFormatted(): void
    {
        $vps = $this->service->findById(101);
        $this->assertNotNull($vps);
        $this->assertEquals(1672531199, $vps->getTime());
        $this->assertEquals(1672531199, $vps->getCreatedAt());
        $this->assertEquals(date('Y-m-d H:i:s', 1672531199), $vps->getCreationDateFormatted());
        $this->assertStringContains('ago', $vps->getCreationDateFormatted('Y-m-d H:i:s', true));
        $this->assertEquals('kvm', $vps->getVirt());
        $this->assertEquals(512, $vps->getSwap());
        $this->assertEquals('2000', $vps->getBandwidth());

        $array = $vps->toArray();
        $this->assertEquals(1672531199, $array['time']);
        $this->assertEquals(date('Y-m-d H:i:s', 1672531199), $array['created_at']);
        $this->assertEquals('kvm', $array['virt']);
        $this->assertEquals(512, $array['swap']);
        $this->assertEquals('2000', $array['bandwidth']);
    }

    public function testCreationDateWhenMissingHandledGracefully(): void
    {
        $vps = $this->service->findById(103);
        $this->assertNotNull($vps);
        $this->assertNull($vps->getTime());
        $this->assertEquals('Not recorded / Unknown', $vps->getCreationDateFormatted());
        $this->assertEquals('Not recorded / Unknown', $vps->getCreationDateFormatted('Y-m-d H:i:s', true));
    }
}
