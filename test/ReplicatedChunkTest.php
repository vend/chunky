<?php

namespace Chunky;

class ReplicatedChunkTest extends Test
{
    public function testLaggedSlave()
    {
        $sut = new ReplicatedChunk(500, '0.2', [
            'continue_lag'  => true,
            'max_pause_lag' => '1000000'
        ]);

        $sut->setSlaves([
            $this->getMockConnectionLaggedSlave()
        ]);

        $sut->interval(10);

        $this->assertTrue($sut->getPaused());
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testLaggedSlaveException()
    {
        $sut = new ReplicatedChunk(500, '0.2', [
            'continue_lag'  => false,
            'max_pause_lag' => '1000000'
        ]);

        $sut->setSlaves([
            $this->getMockConnectionLaggedSlave()
        ]);

        $sut->interval(10);

        $this->assertTrue($sut->getPaused());
    }

    public function testRecoveringSlave()
    {
        $sut = new ReplicatedChunk(500, '0.2', [
            'continue_lag'  => true,
            'max_pause_lag' => '1000000'
        ]);

        $sut->setSlaves([
            $this->getMockConnectionRecoveringSlave()
        ]);

        $sut->interval(10);

        $this->assertTrue($sut->getPaused());
    }

    public function testNormalSlave()
    {
        $sut = new ReplicatedChunk(500, '0.2');

        $sut->setSlaves([
            $this->getMockConnectionNormalSlave()
        ]);

        $sut->interval(10);

        $this->assertFalse($sut->getPaused());
    }

    public function testNoSlave()
    {
        $sut = new ReplicatedChunk(500, '0.2');

        $sut->setSlaves([
            $this->getMockConnectionNotSlave()
        ]);

        $sut->interval(10);

        $this->assertFalse($sut->getPaused());
    }

    protected function getMockConnectionLaggedSlave($seconds = 5)
    {
        $mock = $this->getMockConnection();

        $mock->expects($this->any())
            ->method('fetchAssoc')
            ->will($this->returnValue(['Master_Server_Id' => "1", 'Seconds_Behind_Master' => "$seconds"]));

        return $mock;
    }

    protected function getMockConnectionRecoveringSlave()
    {
        $mock = $this->getMockConnection();

        $mock->expects($this->at(0))
            ->method('fetchAssoc')
            ->will($this->returnValue(['Master_Server_Id' => "1", 'Seconds_Behind_Master' => "5"]));

        $mock->expects($this->at(1))
            ->method('fetchAssoc')
            ->will($this->returnValue(['Master_Server_Id' => "1", 'Seconds_Behind_Master' => "0"]));

        return $mock;
    }

    protected function getMockConnectionNormalSlave()
    {
        $mock = $this->getMockConnection();

        $mock->expects($this->any())
            ->method('fetchAssoc')
            ->will($this->returnValue(['Master_Server_Id' => "1", 'Seconds_Behind_Master' => "0"]));

        return $mock;
    }

    protected function getMockConnectionNotSlave()
    {
        $mock = $this->getMockConnection();

        $mock->expects($this->any())
            ->method('fetchAssoc')
            ->will($this->returnValue([]));

        return $mock;
    }

    protected function getMockConnection()
    {
        return $this->getMockBuilder('Doctrine\DBAL\Connection')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
