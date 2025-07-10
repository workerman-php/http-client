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
use Throwable;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Timer;
use \Workerman\Worker;

/**
 * Class ConnectionPool
 * @package Workerman\Http
 */
class ConnectionPool extends Emitter
{
    /**
     * @var array
     */
    protected array $idle = [];

    /**
     * @var array
     */
    protected array $using = [];

    /**
     * @var int
     */
    protected int $timer = 0;

    /**
     * @var array
     */
    protected array $options = [
        'max_conn_per_addr' => 128,
        'keepalive_timeout' => 15,
        'connect_timeout'   => 30,
        'timeout'           => 30, //普通请求超时时间(秒)
        'stream_timeout'    => 30, //流式响应中每次数据传输之间的最大间隔时间(秒)
        'stream_max_time'   => 0, //流式响应的总最大持续时间(秒),默认0为不限制
    ];

    /**
     * ConnectionPool constructor.
     *
     * @param array $option
     */
    public function __construct(array $option = [])
    {
        $this->options = array_merge($this->options, $option);
    }

    /**
     * Fetch an idle connection.
     *
     * @param $address
     * @param bool $ssl
     * @param string $proxy
     * @return mixed
     * @throws Exception
     */
    public function fetch($address, bool $ssl = false, string $proxy = ''): mixed
    {
        $max_con = $this->options['max_conn_per_addr'];
        $targetAddress = $address;
        $address = ProxyHelper::addressKey($address, $proxy);
        if (!empty($this->using[$address])) {
            if (count($this->using[$address]) >= $max_con) {
                return null;
            }
        }
        if (empty($this->idle[$address])) {
            $connection = $this->create($targetAddress, $ssl, $proxy);
            $this->idle[$address][$connection->id] = $connection;
        }
        $connection = array_pop($this->idle[$address]);
        if (!isset($this->using[$address])) {
            $this->using[$address] = [];
        }
        $this->using[$address][$connection->id] = $connection;
        $connection->pool['request_time'] = time();
        $this->tryToCreateConnectionCheckTimer();
        return $connection;
    }

    /**
     * Recycle a connection.
     *
     * @param $connection AsyncTcpConnection
     */
    public function recycle(AsyncTcpConnection $connection): void
    {
        $connection_id = $connection->id;
        $address = $connection->address;
        unset($this->using[$address][$connection_id]);
        if (empty($this->using[$address])) {
            unset($this->using[$address]);
        }
        if ($connection->getStatus(false) === 'ESTABLISHED') {
            $this->idle[$address][$connection_id] = $connection;
            $connection->pool['idle_time'] = time();
            $connection->onConnect = $connection->onMessage = $connection->onError =
            $connection->onClose = $connection->onBufferFull = $connection->onBufferDrain = null;
        }
        $this->tryToCreateConnectionCheckTimer();
        $this->emit('idle', $address);
    }

    /**
     * Delete a connection.
     *
     * @param $connection
     */
    public function delete($connection): void
    {
        $connection_id = $connection->id;
        $address = $connection->address;
        unset($this->idle[$address][$connection_id]);
        if (empty($this->idle[$address])) {
            unset($this->idle[$address]);
        }
        unset($this->using[$address][$connection_id]);
        if (empty($this->using[$address])) {
            unset($this->using[$address]);
        }
    }

    /**
     * Close timeout connection.
     * @throws Throwable
     */
    public function closeTimeoutConnection(): void
    {
        if (empty($this->idle) && empty($this->using)) {
            Timer::del($this->timer);
            $this->timer = 0;
            return;
        }
        $time = time();
        $keepalive_timeout = $this->options['keepalive_timeout'];
        foreach ($this->idle as $address => $connections) {
            if (empty($connections)) {
                unset($this->idle[$address]);
                continue;
            }
            foreach ($connections as $connection) {
                if ($time - $connection->pool['idle_time'] >= $keepalive_timeout) {
                    $this->delete($connection);
                    $connection->close();
                }
            }
        }

        $connect_timeout = $this->options['connect_timeout'];
        $timeout = $this->options['timeout'];
        foreach ($this->using as $address => $connections) {
            if (empty($connections)) {
                unset($this->using[$address]);
                continue;
            }
            foreach ($connections as $connection) {
                $state = $connection->getStatus(false);
                if ($state === 'CONNECTING') {
                    $diff = $time - $connection->pool['connect_time'];
                    if ($diff >= $connect_timeout) {
                        $connection->onClose = null;
                        if ($connection->onError) {
                            try {
                                call_user_func($connection->onError, $connection, 1, 'connect ' . $connection->getRemoteAddress() . ' timeout after ' . $diff . ' seconds');
                            } catch (Throwable $exception) {
                                $this->delete($connection);
                                $connection->close();
                                throw $exception;
                            }
                        }
                        $this->delete($connection);
                        $connection->close();
                    }
                } elseif ($state === 'ESTABLISHED') {
                    $isStreaming = isset($connection->pool['is_streaming']) && $connection->pool['is_streaming'];
                    $isMaxTime = false;
                    // 流式响应的总最大持续时间(秒)不为0,则需要判断总持续时间是否超过限制
                    $streamMaxTime = $this->options['stream_max_time'];
                    if ($isStreaming) {
                        // 流式连接：基于最后数据接收时间判断超时
                        $lastDataTime = $connection->pool['last_data_time'] ?? $connection->pool['request_time'];
                        $diff = $time - $lastDataTime;
                        $currentTimeout = $this->options['stream_timeout'];
                        if ($streamMaxTime > 0 && ($time - $connection->pool['request_time'] > $streamMaxTime)) {
                            $isMaxTime = true;
                        }
                    } else {
                        // 普通连接：基于请求开始时间判断超时
                        $diff = $time - $connection->pool['request_time'];
                        $currentTimeout = $timeout;
                    }
                    if ($diff >= $currentTimeout || $isMaxTime) {
                        if ($connection->onError) {
                            try {
                                $timeoutType = $isStreaming ? 'stream' : 'read';
                                $timeoutMsg = $isMaxTime ? (' max_time after ' . $streamMaxTime . ' seconds') : (' timeout after ' . $diff . ' seconds');
                                call_user_func($connection->onError, $connection, 128, $timeoutType . ' ' . $connection->getRemoteAddress() . $timeoutMsg);
                            } catch (Throwable $exception) {
                                $this->delete($connection);
                                $connection->close();
                                throw $exception;
                            }
                        }
                        $this->delete($connection);
                        $connection->close();
                    }
                }
            }
        }
        gc_collect_cycles();
    }

    /**
     * Create a connection.
     *
     * @param $address
     * @param bool $ssl
     * @param string $proxy
     * @return AsyncTcpConnection
     * @throws Exception
     */
    protected function create($address, bool $ssl = false, string $proxy = ''): AsyncTcpConnection
    {
        $context = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ],
            'http' => [
                'proxy' => $proxy,
            ]
        ];
        if (!empty( $this->options['context'])) {
            $context = array_merge($context, $this->options['context']);
        }
        if (!$ssl) {
            unset($context['ssl']);
        }
        if (empty($proxy)) {
            unset($context['http']['proxy']);
        }
        if (!class_exists(Worker::class) || is_null(Worker::$globalEvent)) {
            throw new Exception('Only the workerman environment is supported.');
        }
        $connection = new AsyncTcpConnection($address, $context);
        if ($ssl) {
            $connection->transport = 'ssl';
        }
        ProxyHelper::setConnectionProxy($connection, $context);
        $connection->address = ProxyHelper::addressKey($address, $proxy);
        $connection->connect();
        $connection->pool = ['connect_time' => time()];
        return $connection;
    }

    /**
     * Create Timer.
     */
    protected function tryToCreateConnectionCheckTimer(): void
    {
        if (!$this->timer) {
            $this->timer = Timer::add(1, [$this, 'closeTimeoutConnection']);
        }
    }
}
