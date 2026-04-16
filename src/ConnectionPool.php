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
use Workerman\Timer;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

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
        'timeout'           => 30,
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
                    $diff = $time - $connection->pool['request_time'];
                    if ($diff >= $timeout) {
                        if ($connection->onError) {
                            try {
                                call_user_func($connection->onError, $connection, 128, 'read ' . $connection->getRemoteAddress() . ' timeout after ' . $diff . ' seconds');
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
        ];
        
        // Extract hostname from address for SSL peer_name
        if ($ssl) {
            $hostname = $this->extractHostname($address);
            if ($hostname) {
                $context['ssl']['peer_name'] = $hostname;
            }
        }
        if (!empty( $this->options['context'])) {
            $context = array_merge($context, $this->options['context']);
        }
        $context = ProxyHelper::applyProxyToContext($context, $proxy);
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
    
    /**
     * Extract hostname from address
     *
     * @param string $address
     * @return string|null
     */
    protected function extractHostname(string $address): ?string
    {
        $parsed = parse_url($address);
        return $parsed['host'] ?? null;
    }
}
