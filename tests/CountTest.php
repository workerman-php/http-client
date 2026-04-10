<?php
namespace tests;

use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;
use Workerman\Http\ParallelClient;
use Workerman\Timer;

class CountTest extends TestCase
{

    /**
     * Test initial queue count is zero
     */
    public function testInitialQueueCountIsZero()
    {
        $http = new Client();
        $this->assertSame(0, $http->queueCount());
    }

    /**
     * Test queue count with address parameter
     */
    public function testQueueCountWithAddressIsZero()
    {
        $http = new Client();
        $this->assertSame(0, $http->queueCount('tcp://127.0.0.1:7171'));
    }

    /**
     * Test queue count after synchronous request completes
     */
    public function testQueueCountAfterSynchronousRequest()
    {
        $http = new Client();
        $http->get('http://127.0.0.1:7171/get?k=v');
        $this->assertSame(0, $http->queueCount());
    }

    /**
     * Test queue count with limited connections
     */
    public function testQueueCountWithLimitedConnections()
    {
        $http = new Client(['max_conn_per_addr' => 1]);
        $completed = 0;

        for ($i = 0; $i < 3; $i++) {
            $http->get('http://127.0.0.1:7171/get?i=' . $i, function ($response) use (&$completed) {
                $completed++;
            });
        }

        // 1 connection running, 2 tasks waiting in queue
        $this->assertSame(2, $http->queueCount());
        $this->assertSame(2, $http->queueCount('tcp://127.0.0.1:7171'));

        // Non-existent address should return 0
        $this->assertSame(0, $http->queueCount('tcp://127.0.0.1:9999'));

        for ($i = 0; $i < 30; $i++) {
            if ($completed >= 3) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertSame(3, $completed);
        $this->assertSame(0, $http->queueCount());
    }

    /**
     * Test queue count after callback request completes
     */
    public function testQueueCountAfterCallbackRequestCompletes()
    {
        $http = new Client();
        $successCalled = false;

        $http->get('http://127.0.0.1:7171/get?k=v', function ($response) use (&$successCalled) {
            $successCalled = true;
        });

        for ($i = 0; $i < 10; $i++) {
            if ($successCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertTrue($successCalled);
        $this->assertSame(0, $http->queueCount());
    }

    /**
     * Test ParallelClient inherits queueCount
     */
    public function testParallelClientQueueCount()
    {
        $http = new ParallelClient();
        $this->assertSame(0, $http->queueCount());

        $http->push('http://127.0.0.1:7171/get?k=v1');
        $http->push('http://127.0.0.1:7171/get?k=v2');
        $results = $http->await();

        $this->assertCount(2, $results);
        $this->assertSame(0, $http->queueCount());
    }

}
