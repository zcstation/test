<?php


namespace zcstation;


use Exception;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\Socket;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Process;
use Swoole\WebSocket\CloseFrame;
use function Swoole\Coroutine\run;

class ZServer
{
    /**
     * @var string 监听地址
     */
    public $host;

    /**
     * @var int 监听端口
     */
    public $port;

    /**
     * @var callable $_httpHandle http响应处理回调
     */
    private $_httpHandle;

    /**
     * @var callable $_webSocketHandle webSocket响应处理回调
     */
    private $_webSocketHandle;

    /**
     * @var Server $_httpServer http服务
     */
    private $_httpServer;

    /**
     * @var Response[] webSocket连接对象列表
     */
    public $connections = [];

    /**
     * ZServer constructor.
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @throws Exception
     */
    public function __construct($host = "0.0.0.0", $port = 19501)
    {
        // 扩展检查
        if (!extension_loaded('swoole')) {
            throw new Exception("The swoole extension is missing. Please check your PHP configuration");
        }
        // 版本检查
        if (version_compare(swoole_version(), '4.4.0') < 0) {
            throw new Exception("Your version of swoole(" . swoole_version() . ") is too old. " .
                "Please install swoole version 4.4.0 or newer");
        }
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * 控制台输出
     * @param $message
     * @param int $type
     */
    public function writeln($message, $type = 0)
    {
        echo date('[ Y-m-d H:i:s ] ') . "\e[{$type}m" . $message . "\e[0m" . PHP_EOL;
    }

    /**
     * 提示信息
     * @param $message
     */
    public function info($message)
    {
        $this->writeln($message, 32);
    }

    /**
     * 错误信息
     * @param $message
     */
    public function error($message)
    {
        $this->writeln($message, 31);
    }

    /**
     * 警告信息
     * @param $message
     */
    public function warning($message)
    {
        $this->writeln($message, 33);
    }

    /**
     * 设置http回调
     * @param callable $callback
     * @return ZServer
     */
    public function setHttpHandle(callable $callback)
    {
        $this->_httpHandle = $callback;
        return $this;
    }

    /**
     * 设置webSocket回调
     * @param callable $callback
     * @return ZServer
     */
    public function setWebSocketHandle(callable $callback)
    {
        $this->_webSocketHandle = $callback;
        return $this;
    }

    /**
     * 移除连接
     * @param Response $connection
     */
    private function removeConnection(Response $connection)
    {
        if (false === $index = array_search($connection, $this->connections)) {
            return;
        }
        array_splice($this->connections, $index, 1);
    }

    /**
     * 添加客户端
     * @param Response $connection
     */
    private function addConnection(Response $connection)
    {
        if (array_search($connection, $this->connections) !== false) {
            return;
        }
        $this->connections[] = $connection;
    }

    /**
     * 获取客户端地址信息
     * @param Response $connection
     * @param string $type
     * @return string|null
     */
    public static function getAddress(Response $connection, $type = null)
    {
        /**
         * @var Socket $socket
         */
        $socket = $connection->socket;

        $peer = $socket->getpeername();

        return is_null($type) ? $peer['address'] . ':' . $peer['port'] : (isset($peer[$type]) ? $peer[$type] : null);
    }

    /**
     * 启动程序
     */
    public function start()
    {
        run(function () {
            $this->_httpServer = new Server($this->host, $this->port);
            // websocket请求
            $this->_httpServer->handle('/websocket', function (Request $request, Response $response) {
                $response->upgrade();
                // 加入连接
                $this->addConnection($response);
                $this->info($this->getAddress($response) . ' 已连接');
                if ($this->_webSocketHandle) {
                    call_user_func($this->_webSocketHandle, $request, $response, null);
                }
                while (true) {
                    $frame = $response->recv();
                    // 连接关闭标志
                    $flag = false;
                    if ($frame === '') {
                        $response->close();
                        $flag = true;
                    } else if ($frame === false) {
                        $this->error("WebSocket Error：" . swoole_strerror(swoole_last_error()));
                        $flag = true;
                    } else if (get_class($frame) === CloseFrame::class) {
                        $flag = true;
                    }
                    if ($this->_webSocketHandle) {
                        $flag = 'close' == call_user_func($this->_webSocketHandle,
                                $request, $response, $flag ? false : $frame);
                    }
                    // 关闭连接
                    if ($flag) {
                        break;
                    }
                }
                // 移除连接
                $this->removeConnection($response);
                $this->warning($this->getAddress($response) . ' 已断开连接');
            });
            // http请求
            $this->_httpServer->handle('/', function (Request $request, Response $response) {
                if ($this->_httpHandle) {
                    call_user_func($this->_httpHandle, $request, $response);
                } else {
                    $response->end("");
                }
            });
            // 启动成功
            $this->info("Http服务启动成功，正在监听 http://" . $this->host . ':' . $this->port);
            $this->writeln("按 Ctrl-C 退出");
            // 退出信号
            Process::signal(SIGINT, function ($signalNo) {
                $this->warning("收到信号 " . $signalNo . "，程序停止运行");
                // 关闭所有websocket连接
                foreach ($this->connections as $connection) {
                    /**
                     * @var Socket $socket
                     */
                    $socket = $connection->socket;
                    // 关闭socket才能关闭连接
                    $socket->close();
                }
                $this->_httpServer->shutdown();
            });
            $this->_httpServer->start();
        });
    }
}