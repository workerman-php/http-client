<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Http;

use Exception;
use Psr\Http\Message\MessageInterface;
use RuntimeException;
use Throwable;
use \Workerman\Connection\AsyncTcpConnection;
use Workerman\Psr7\MultipartStream;
use Workerman\Psr7\UriResolver;
use Workerman\Psr7\Uri;
use function Workerman\Psr7\_parse_message;
use function Workerman\Psr7\rewind_body;
use function Workerman\Psr7\str;

/**
 * Class Request
 * @package Workerman\Http
 */
#[\AllowDynamicProperties]
class Request extends \Workerman\Psr7\Request
{
    /**
     * @var ?AsyncTcpConnection
     */
    protected ?AsyncTcpConnection $connection = null;

    /**
     * @var ?Emitter
     */
    protected ?Emitter $emitter = null;

    /**
     * @var ?Response
     */
    protected ?Response $response = null;

    /**
     * @var string
     */
    protected string $receiveBuffer = '';

    /**
     * @var int
     */
    protected int $expectedLength = 0;

    /**
     * @var int
     */
    protected int $chunkedLength = 0;

    /**
     * @var string
     */
    protected string $chunkedData = '';

    /**
     * @var bool
     */
    protected bool $writeable = true;

    /**
     * @var bool
     */
    protected bool $selfConnection = false;

    /**
     * @var array
     */
    protected array $options = [
        'allow_redirects' => [
            'max' => 5
        ]
    ];

    /**
     * Request constructor.
     * @param string $url
     */
    public function __construct($url)
    {
        $this->emitter = new Emitter();
        $headers = [
            'User-Agent' => 'workerman/http-client',
            'Connection' => 'keep-alive'
        ];
        parent::__construct('GET', $url, $headers, '', '1.1');
    }

    /**
     * @param $options
     * @return $this
     */
    public function setOptions($options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param $event
     * @param $callback
     * @return $this
     */
    public function on($event, $callback): static
    {
        $this->emitter->on($event, $callback);
        return $this;
    }

    /**
     * @param $event
     * @param $callback
     * @return $this
     */
    public function once($event, $callback): static
    {
        $this->emitter->once($event, $callback);
        return $this;
    }

    /**
     * @param $event
     */
    public function emit($event): void
    {
        $args = func_get_args();
        call_user_func_array(array($this->emitter, 'emit'), $args);
    }

    /**
     * @param $event
     * @param $listener
     * @return $this
     */
    public function removeListener($event, $listener): static
    {
        $this->emitter->removeListener($event, $listener);
        return $this;
    }

    /**
     * @param null $event
     * @return $this
     */
    public function removeAllListeners($event = null): static
    {
        $this->emitter->removeAllListeners($event);
        return $this;
    }

    /**
     * @param $event
     * @return $this
     */
    public function listeners($event): static
    {
        $this->emitter->listeners($event);
        return $this;
    }

    /**
     * Connect.
     *
     * @return void
     * @throws Exception
     */
    protected function connect(): void
    {
        $host = $this->getUri()->getHost();
        $port = $this->getUri()->getPort();
        if (!$port) {
            $port = $this->getDefaultPort();
        }
        $context = [];
        if (!empty( $this->options['context'])) {
            $context = $this->options['context'];
        }
        $ssl = $this->getUri()->getScheme() === 'https';
        if (!$ssl) {
            unset($context['ssl']);
        }
        $connection = new AsyncTcpConnection("tcp://$host:$port", $context);
        if ($ssl) {
            $connection->transport = 'ssl';
        }
        ProxyHelper::setConnectionProxy($connection, $context);
        $this->attachConnection($connection);
        $this->selfConnection = true;
        $connection->connect();
    }

    /**
     * @param string|array $data
     * @return $this
     */
    public function write(string|array $data = ''): static
    {
        if (!$this->writeable()) {
            $this->emitError(new RuntimeException('Request pending and can not send request again'));
            return $this;
        }

        if (empty($data) && $data !== '0') {
            return $this;
        }

        if (is_array($data)) {
            if (isset($data['multipart'])) {
                $multipart = new MultipartStream($data['multipart']);
                $this->withHeader('Content-Type', 'multipart/form-data; boundary=' . $multipart->getBoundary());
                $data = $multipart;
            } else {
                $data = http_build_query($data, '', '&');
            }
        }

        $this->getBody()->write($data);
        return $this;
    }

    /**
     * @param $buffer
     * @return void
     */
    public function writeToResponse($buffer): void
    {
        $this->emit('progress', $buffer);
        $this->response->getBody()->write($buffer);
    }

    /**
     * @param string $data
     * @throws Exception
     */
    public function end(string $data = ''): void
    {
        if (isset($this->options['version'])) {
            $this->withProtocolVersion($this->options['version']);
        }

        if (isset($this->options['method'])) {
            $this->withMethod($this->options['method']);
        }

        if (isset($this->options['headers'])) {
            foreach ($this->options['headers'] as $key => $value) {
                $this->withHeader($key, $value);
            }
        }

        $query = $this->options['query'] ?? '';
        if ($query || $query === '0') {
            $userParams = [];
            if (is_array($query)) {
                $userParams = $query;
            } else {
                parse_str((string)$query, $userParams);
            }

            $originalParams = [];
            parse_str($this->getUri()->getQuery(), $originalParams);
            $mergedParams = array_merge($originalParams, $userParams);
            $mergedQuery = http_build_query($mergedParams, '', '&', PHP_QUERY_RFC3986);
            $uri = $this->getUri()->withQuery($mergedQuery);
            $this->withUri($uri);
        }

        if ($data !== '') {
            $this->write($data);
        }

        if ((($data || $data === '0') || $this->getBody()->getSize()) && !$this->hasHeader('Content-Type')) {
            $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        if (!$this->connection) {
            $this->connect();
        } else {
            if ($this->connection->getStatus(false) === 'CONNECTING') {
                $this->connection->onConnect = array($this, 'onConnect');
                return;
            }
            $this->doSend();
        }
    }

    /**
     * @return bool
     */
    public function writeable(): bool
    {
        return $this->writeable;
    }

    public function doSend(): void
    {
        if (!$this->writeable()) {
            $this->emitError(new RuntimeException('Request pending and can not send request again'));
            return;
        }

        $this->writeable = false;

        $body_size = $this->getBody()->getSize();
        if ($body_size) {
            $this->withHeaders(['Content-Length' => $body_size]);
        }

        $package = str($this);
        $this->connection->send($package);
    }

    public function onConnect(): void
    {
        try {
            $this->doSend();
        } catch (Throwable $e) {
            $this->emitError($e);
        }
    }

    /**
     * @param $connection
     * @param $receive_buffer
     */
    public function onMessage($connection, $receive_buffer): void
    {
        try {
            $this->receiveBuffer .= $receive_buffer;
            if (!strpos($this->receiveBuffer, "\r\n\r\n")) {
                return;
            }

            $response_data = _parse_message($this->receiveBuffer);

            if (!preg_match('/^HTTP\/.* [0-9]{3}( .*|$)/', $response_data['start-line'])) {
                throw new \InvalidArgumentException('Invalid response string: ' . $response_data['start-line']);
            }
            $parts = explode(' ', $response_data['start-line'], 3);

            $this->response = new Response(
                $parts[1],
                $response_data['headers'],
                '',
                explode('/', $parts[0])[1],
                $parts[2] ?? null
            );

            $this->checkComplete($response_data['body']);
        } catch (Throwable $e) {
            $this->emitError($e);
        }
    }

    /**
     * @param $body
     */
    protected function checkComplete($body): void
    {
        $status_code = $this->response->getStatusCode();
        $content_length = $this->response->getHeaderLine('Content-Length');
        if ($content_length === '0' || ($status_code >= 100 && $status_code < 200)
            || $status_code === 204 || $status_code === 304) {
            $this->emitSuccess();
            return;
        }

        $transfer_encoding = $this->response->getHeaderLine('Transfer-Encoding');
        // Chunked
        if ($transfer_encoding && !str_contains($transfer_encoding, 'identity')) {
            $this->connection->onMessage = array($this, 'handleChunkedData');
            $this->handleChunkedData($this->connection, $body);
        } else {
            $this->connection->onMessage = array($this, 'handleData');
            $content_length = (int)$this->response->getHeaderLine('Content-Length');
            if (!$content_length) {
                // Wait close
                $this->connection->onClose = array($this, 'emitSuccess');
            } else {
                $this->expectedLength = $content_length;
            }
            $this->handleData($this->connection, $body);
        }
    }

    /**
     * @param $connection
     * @param $data
     */
    public function handleData($connection, $data): void
    {
        try {
            $body = $this->response->getBody();
            $this->writeToResponse($data);
            if ($this->expectedLength) {
                $receive_length = $body->getSize();
                if ($this->expectedLength <= $receive_length) {
                    $this->emitSuccess();
                }
            }
        } catch (Throwable $e) {
            $this->emitError($e);
        }
    }

    /**
     * @param $connection
     * @param $buffer
     */
    public function handleChunkedData($connection, $buffer): void
    {
        try {
            if ($buffer !== '') {
                $this->chunkedData .= $buffer;
            }

            $receive_len = strlen($this->chunkedData);
            if ($receive_len < 2) {
                return;
            }
            // Get chunked length
            if ($this->chunkedLength === 0) {
                $crlf_position = strpos($this->chunkedData, "\r\n");
                if (false === $crlf_position) {
                    if (strlen($this->chunkedData) > 1024) {
                        $this->emitError(new RuntimeException('bad chunked length'));
                    }
                    return;
                }

                $length_chunk = substr($this->chunkedData, 0, $crlf_position);
                if (str_contains($length_chunk, ';')) {
                    list($length_chunk) = explode(';', $length_chunk, 2);
                }
                $length = hexdec(ltrim(trim($length_chunk), "0"));
                if ($length === 0) {
                    $this->emitSuccess();
                    return;
                }
                $this->chunkedLength = $length + 2;
                $this->chunkedData = substr($this->chunkedData, $crlf_position + 2);
                $this->handleChunkedData($connection, '');
                return;
            }
            // Get chunked data
            if ($receive_len >= $this->chunkedLength) {
                $this->writeToResponse(substr($this->chunkedData, 0, $this->chunkedLength - 2));
                $this->chunkedData = substr($this->chunkedData, $this->chunkedLength);
                $this->chunkedLength = 0;
                $this->handleChunkedData($connection, '');
            }
        } catch (Throwable $e) {
            $this->emitError($e);
        }
    }

    /**
     * onError.
     */
    public function onError($connection, $code, $msg): void
    {
        $this->emitError(new RuntimeException($msg, $code));
    }

    /**
     * emitSuccess.
     */
    public function emitSuccess(): void
    {
        $this->emit('success', $this->response);
    }

    public function emitError($e): void
    {
        try {
            $this->emit('error', $e);
        } finally {
            $this->connection && $this->connection->destroy();
        }
    }

    /**
     * redirect.
     *
     * @param Request $request
     * @param Response $response
     * @return bool|MessageInterface
     */
    public static function redirect(Request $request, Response $response): bool|MessageInterface
    {
        $options = $request->getOptions();
        if (!str_starts_with($response->getStatusCode(), '3')
            || !$response->hasHeader('Location')
            || self::getMaxRedirects($options)
        ) {
            return false;
        }
        $location = UriResolver::resolve(
            $request->getUri(),
            new Uri($response->getHeaderLine('Location'))
        );
        rewind_body($request);

        return (new Request($location))->setOptions($options)->withBody($request->getBody());
    }

    /**
     * @param array $options
     * @return bool
     */
    private static function getMaxRedirects(array &$options): bool
    {
        $current = $options['__redirect_count'] ?? 0;
        $options['__redirect_count'] = $current + 1;
        $max = $options['allow_redirects']['max'];

        return $options['__redirect_count'] > $max;
    }

    /**
     * onUnexpectClose.
     */
    public function onUnexpectClose(): void
    {
        $this->emitError(new RuntimeException('The connection to ' . $this->connection->getRemoteIp() . ' has been closed.'));
    }

    /**
     * @return int
     */
    protected function getDefaultPort(): int
    {
        return ('https' === $this->getUri()->getScheme()) ? 443 : 80;
    }

    /**
     * detachConnection.
     *
     * @return void
     */
    public function detachConnection(): void
    {
        $this->cleanConnection();
        // 不是连接池的连接则断开
        if ($this->selfConnection) {
            $this->connection->close();
            return;
        }
        $this->writeable = true;
    }

    /**
     * @return ?AsyncTcpConnection
     */
    public function getConnection(): ?AsyncTcpConnection
    {
        return $this->connection;
    }

    /**
     * attachConnection.
     *
     * @param $connection AsyncTcpConnection
     * @return $this
     */
    public function attachConnection(AsyncTcpConnection $connection): static
    {
        $connection->onConnect = array($this, 'onConnect');
        $connection->onMessage = array($this, 'onMessage');
        $connection->onError   = array($this, 'onError');
        $connection->onClose   = array($this, 'onUnexpectClose');
        $this->connection = $connection;

        return $this;
    }

    /**
     * cleanConnection.
     */
    protected function cleanConnection(): void
    {
        $connection = $this->connection;
        $connection->onConnect = $connection->onMessage = $connection->onError =
        $connection->onClose = $connection->onBufferFull = $connection->onBufferDrain = null;
        $this->connection = null;
        $this->emitter->removeAllListeners();
    }
}
