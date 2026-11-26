<?php
namespace tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Http\Request;
use function Workerman\Psr7\parse_request;

class RequestSerializationTest extends TestCase
{
    #[DataProvider('contentLengthCases')]
    public function testContentLengthOnTheWire(string $method, string $body, array $headers, array $expectedLength): void
    {
        $connection = $this->createMock(AsyncTcpConnection::class);
        $connection->method('getStatus')->with(false)->willReturn('ESTABLISHED');
        $connection->expects($this->once())->method('send')->willReturnCallback(
            function (string $message) use ($body, $expectedLength): bool {
                $sentRequest = parse_request($message);
                $this->assertSame($expectedLength, $sentRequest->getHeader('Content-Length'));
                $this->assertSame($body, (string)$sentRequest->getBody());
                return true;
            }
        );

        $request = new Request('http://example.com/');
        $request->attachConnection($connection);
        $request->setOptions(['method' => $method, 'headers' => $headers]);
        $request->end($body);
    }

    public static function contentLengthCases(): iterable
    {
        yield 'empty GET' => ['GET', '', [], []];
        yield 'empty GET with explicit length' => ['GET', '', ['content-length' => '0'], ['0']];
        yield 'empty GET with incorrect length' => ['GET', '', ['content-length' => '1'], ['0']];
        yield 'GET with one byte' => ['GET', '0', [], ['1']];
        yield 'empty POST' => ['POST', '', [], ['0']];
        yield 'empty DELETE' => ['DELETE', '', [], ['0']];
        yield 'POST with body' => ['POST', 'abc', [], ['3']];
    }
}
