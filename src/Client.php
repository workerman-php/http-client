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
use RuntimeException;
use Throwable;
use Workerman\Coroutine\Channel;
use Workerman\Coroutine;
use Workerman\Timer;

/**
 * Class Http\Client
 * @package Workerman\Http
 */
#[\AllowDynamicProperties]
class Client
{
    /**
     *
     *[
     *   address=>[
     *        [
     *        'url'=>x,
     *        'address'=>x
     *        'options'=>['method', 'data'=>x, 'success'=>callback, 'error'=>callback, 'headers'=>[..], 'version'=>1.1]
     *        ],
     *        ..
     *   ],
     *   ..
     * ]
     * @var array
     */
    protected array $queue = [];

    /**
     * @var ?ConnectionPool
     */
    protected ?ConnectionPool $_connectionPool = null;

    protected Channel $locker;

    /**
     * Client constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->_connectionPool = new ConnectionPool($options);
        $this->_connectionPool->on('idle', array($this, 'process'));
        $this->locker = new Channel(1);
    }

    /**
     * Request.
     *
     * @param $url string
     * @param array $options ['method'=>'get', 'data'=>x, 'success'=>callback, 'error'=>callback, 'headers'=>[..], 'version'=>1.1]
     * @return mixed|Response
     * @throws Throwable
     */
    public function request(string $url, array $options = []): mixed
    {
        $options['url'] = $url;
        $suspend = false;
        $isCoroutine = !isset($options['success']) && Coroutine::isCoroutine();
        if ($isCoroutine) {
            $options['is_coroutine'] = true;
            $result = $exception = null;
            $coroutine = Coroutine::getCurrent();
            $options['success'] = function ($response) use ($coroutine, &$result, &$suspend) {
                $result = $response;
                $suspend && $coroutine->resume();
            };
            $options['error'] = function ($throwable) use ($coroutine, &$exception, &$suspend) {
                $exception = $throwable;
                $suspend && $coroutine->resume();
            };
        }
        try {
            $address = $this->parseAddress($url);
            $this->queuePush($address, ['url' => $url, 'address' => $address, 'options' => $options]);
            $this->process($address);
        } catch (Throwable $exception) {
            $this->deferError($options, $exception);
            if ($isCoroutine) {
                throw $exception;
            }
            return null;
        }
        if ($isCoroutine) {
            $suspend = true;
            $coroutine->suspend();
            if ($exception) {
                throw $exception;
            }
            return $result;
        }
        return null;
    }

    /**
     * Get.
     *
     * @param $url
     * @param null $success_callback
     * @param null $error_callback
     * @return mixed|Response
     * @throws Throwable
     */
    public function get($url, $success_callback = null, $error_callback = null): mixed
    {
        $options = [];
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }
        return $this->request($url, $options);
    }

    /**
     * Post.
     *
     * @param $url
     * @param array $data
     * @param null $success_callback
     * @param null $error_callback
     * @return mixed|Response
     * @throws Throwable
     */
    public function post($url, $data = [], $success_callback = null, $error_callback = null): mixed
    {
        $options = [];
        if ($data) {
            $options['data'] = $data;
        }
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }
        $options['method'] = 'POST';
        return $this->request($url, $options);
    }

    /**
     * Process.
     * User should not call this.
     *
     * @param $address
     * @return void
     * @throws Exception
     */
    public function process($address): void
    {
        $this->locker->push(true);
        $task = $this->queueCurrent($address);
        if (!$task) {
            $this->locker->pop();
            return;
        }

        $url = $task['url'];
        $address = $task['address'];

        $connection = $this->_connectionPool->fetch($address, strpos($url, 'https') === 0, $task['options']['proxy'] ?? '');
        // No connection is in idle state then wait.
        if (!$connection) {
            $this->locker->pop();
            return;
        }

        $connection->errorHandler = function(Throwable $exception) use ($task) {
            $this->deferError($task['options'], $exception);
        };
        $this->queuePop($address);
        $this->locker->pop();
        $options = $task['options'];
        $request = new Request($url);
        $data = $options['data'] ?? '';
        if ($data || $data === '0' || $data === 0) {
            $method = isset($options['method']) ? strtoupper($options['method']) : null;
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                $request->write($options['data']);
            } else {
                $options['query'] = $data;
            }
        }
        $request->setOptions($options)->attachConnection($connection);

        $client = $this;
        $request->once('success', function($response) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request, $response);
            try {
                $new_request = Request::redirect($request, $response);
            } catch (Exception $exception) {
                $this->deferError($task['options'], $exception);
                return;
            }
            // No redirect.
            if (!$new_request) {
                if (!empty($task['options']['success'])) {
                    call_user_func($task['options']['success'], $response);
                }
                return;
            }

            // Redirect.
            $uri = $new_request->getUri();
            $url = (string)$uri;
            $options = $new_request->getOptions();
            // According to RFC 7231, for HTTP status codes 301, 302, or 303, the client should switch the request
            // method to GET and remove any payload data
            if (in_array($response->getStatusCode(), [301, 302, 303])) {
                $options['method'] = 'GET';
                $options['data'] = NULL;
            }
            $address = $this->parseAddress($url);
            $task = [
                'url'      => $url,
                'options'  => $options,
                'address'  => $address
            ];
            $this->queueUnshift($address, $task);
            $this->process($address);
        })->once('error', function($exception) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request);
            $this->deferError($task['options'], $exception);
        });

        if (isset($options['progress'])) {
            $request->on('progress', $options['progress']);
        }

        if (isset($options['response'])) {
            $request->on('response', $options['response']);
        }

        $state = $connection->getStatus(false);
        if ($state === 'CLOSING' || $state === 'CLOSED') {
            $connection->reconnect();
        }

        $state = $connection->getStatus(false);
        if ($state === 'CLOSED' || $state === 'CLOSING') {
            return;
        }

        $request->end('');
    }

    /**
     * Recycle connection from request.
     *
     * @param $request Request
     * @param $response Response|null
     */
    public function recycleConnectionFromRequest(Request $request, ?Response $response = null): void
    {
        $connection = $request->getConnection();
        if (!$connection) {
            return;
        }
        $connection->onConnect = $connection->onClose = $connection->onMessage = $connection->onError = null;
        $request_header_connection = strtolower($request->getHeaderLine('Connection'));
        $response_header_connection = $response ? strtolower($response->getHeaderLine('Connection')) : '';
        // Close Connection without header Connection: keep-alive
        if ('keep-alive' !== $request_header_connection || 'keep-alive' !== $response_header_connection || $request->getProtocolVersion() !== '1.1') {
            $connection->close();
        }
        $request->detachConnection($connection);
        $this->_connectionPool->recycle($connection);
    }

    /**
     * Parse address from url.
     *
     * @param $url
     * @return string
     */
    protected function parseAddress($url): string
    {
        $info = parse_url($url);
        if (empty($info) || !isset($info['host'])) {
            throw new RuntimeException("invalid url: $url");
        }
        $port = $info['port'] ?? (str_starts_with($url, 'https') ? 443 : 80);
        return "tcp://{$info['host']}:{$port}";
    }

    /**
     * Queue push.
     *
     * @param $address
     * @param $task
     */
    protected function queuePush($address, $task): void
    {
        if (!isset($this->queue[$address])) {
            $this->queue[$address] = [];
        }
        $this->queue[$address][] = $task;
    }

    /**
     * Queue unshift.
     *
     * @param $address
     * @param $task
     */
    protected function queueUnshift($address, $task): void
    {
        if (!isset($this->queue[$address])) {
            $this->queue[$address] = [];
        }
        $this->queue[$address] += [$task];
    }

    /**
     * Queue current item.
     *
     * @param $address
     * @return mixed|null
     */
    protected function queueCurrent($address): mixed
    {
        if (empty($this->queue[$address])) {
            return null;
        }
        reset($this->queue[$address]);
        return current($this->queue[$address]);
    }

    /**
     * Queue pop.
     *
     * @param $address
     */
    protected function queuePop($address): void
    {
        unset($this->queue[$address][key($this->queue[$address])]);
        if (empty($this->queue[$address])) {
            unset($this->queue[$address]);
        }
    }

    /**
     * Queue count.
     *
     * @param $address
     * @return int
     */
    public function queueCount($address = null): int
    {
        if ($address !== null) {
            return count($this->queue[$address] ?? []);
        }
        $count = 0;
        foreach ($this->queue as $tasks) {
            $count += count($tasks);
        }
        return $count;
    }

    /**
     * @param $options
     * @param $exception
     * @return void
     */
    protected function deferError($options, $exception): void
    {
        if ($options['is_coroutine'] ?? false) {
            if ($options['error']) {
                call_user_func($options['error'], $exception);
                return;
            }
            throw $exception;
        }
        if (isset($options['error'])) {
            Timer::add(0.000001, $options['error'], [$exception], false);
        }
    }
}
