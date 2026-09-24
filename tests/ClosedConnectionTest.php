<?php
namespace tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Http\Client;
use Workerman\Http\ConnectionPool;
use Workerman\Http\Request;
use Workerman\Timer;

class ClosedConnectionTest extends TestCase
{

    /**
     * A connection closed before it reached ESTABLISHED never carried the request, so it must
     * be reported as a connect-stage failure (error code 1) instead of "has been closed".
     */
    public function testClosedBeforeEstablishedIsReportedAsConnectFailure()
    {
        $request = new Request('http://127.0.0.1:7171/get');
        $connection = new AsyncTcpConnection('tcp://127.0.0.1:7171');
        // Still CONNECTING: the request has not been written anywhere yet.
        $request->attachConnection($connection);

        $captured = null;
        $request->once('error', function ($e) use (&$captured) {
            $captured = $e;
        });
        $request->onUnexpectClose();

        $this->assertInstanceOf(RuntimeException::class, $captured);
        $this->assertSame(1, $captured->getCode());
        $this->assertStringContainsString('connect 127.0.0.1 fail after', $captured->getMessage());
        $this->assertStringContainsString('before the request was sent', $captured->getMessage());
        $this->assertStringNotContainsString('has been closed', $captured->getMessage());
    }

    /**
     * A connection closed after establishment keeps the original message (unchanged behavior).
     */
    public function testClosedAfterEstablishedKeepsOriginalMessage()
    {
        $connection = new AsyncTcpConnection('tcp://127.0.0.1:7171');
        $established = false;
        $connection->onConnect = function () use (&$established) {
            $established = true;
        };
        $connection->connect();
        for ($i = 0; $i < 40 && !$established; $i++) {
            Timer::sleep(0.05);
        }
        $this->assertTrue($established, 'test connection was not established');

        $request = new Request('http://127.0.0.1:7171/get');
        $request->attachConnection($connection);

        $captured = null;
        $request->once('error', function ($e) use (&$captured) {
            $captured = $e;
        });
        $request->onUnexpectClose();

        $this->assertInstanceOf(RuntimeException::class, $captured);
        $this->assertSame(0, $captured->getCode());
        $this->assertSame('The connection to 127.0.0.1 has been closed.', $captured->getMessage());
    }

    /**
     * An idle pooled connection closed by the peer is dropped from the pool, and the next
     * request is served by a fresh connection.
     */
    public function testIdleConnectionClosedByPeerIsDroppedFromPool()
    {
        $http = new Client(['max_conn_per_addr' => 1, 'keepalive_timeout' => 30]);

        $status = null;
        $http->get('http://127.0.0.1:7171/reply-then-close', function ($response) use (&$status) {
            $status = $response->getStatusCode();
        }, function ($exception) use (&$status) {
            $status = $exception->getMessage();
        });
        for ($i = 0; $i < 40 && $status === null; $i++) {
            Timer::sleep(0.05);
        }
        $this->assertEquals(200, $status);

        $pool = $this->poolOf($http);
        Timer::sleep(0.1);
        $this->assertNotEmpty($this->idleOf($pool), 'the connection should be pooled after the response');

        // The server closes the connection 0.2s after the response: the pool must drop it
        // instead of handing it to the next request.
        Timer::sleep(0.6);
        $this->assertSame([], $this->idleOf($pool), 'a closed idle connection must not stay in the pool');

        $again = null;
        $http->get('http://127.0.0.1:7171/get', function ($response) use (&$again) {
            $again = $response->getStatusCode();
        }, function ($exception) use (&$again) {
            $again = $exception->getMessage();
        });
        for ($i = 0; $i < 40 && $again === null; $i++) {
            Timer::sleep(0.05);
        }
        $this->assertEquals(200, $again);
    }

    private function poolOf(Client $client): ConnectionPool
    {
        return (new ReflectionProperty(Client::class, '_connectionPool'))->getValue($client);
    }

    private function idleOf(ConnectionPool $pool): array
    {
        return (new ReflectionProperty(ConnectionPool::class, 'idle'))->getValue($pool);
    }

}
