<?php

use Swoole\Http\Request;
use Swoole\Http\Response;
use zcstation\ZServer;

require_once __DIR__ . '/vendor/autoload.php';

$server = new ZServer();

define('JSON_OUTPUT_OPTIONS', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$server->setHttpHandle(function (Request $request, Response $response) {
    // 发送文件
    $path = $request->server['request_uri'];
    $filename = __DIR__ . '/public' . ($path == '/' ? '/index.html' : $path);
    if (file_exists($filename) && is_file($filename)) {
        $response->sendfile($filename);
        return;
    }
    // 其他非文件地址
    $response->setHeader('Content-Type', 'text/plain;charset=utf-8');
    $response->end(json_encode($request->server, JSON_OUTPUT_OPTIONS));
})
    ->setWebSocketHandle(function (Request $request, Response $response, $frame) use ($server) {
        // $frame === null 表示客户端连接
        if (null === $frame) {
            foreach ($server->connections as $connection) {
                if ($connection !== $response) {
                    $connection->push(json_encode([
                        "client" => ZServer::getAddress($response),
                        'user-agent' => $request->header['user-agent'],
                        "type" => "connect"
                    ], JSON_OUTPUT_OPTIONS));
                }
            }
            return null;
        }
        // false === $frame 表示连接已断开，返回字符串 close 表示服务器主动关闭连接，可以加入自己的逻辑，主动关闭连接
        if (false === $frame) {
            foreach ($server->connections as $connection) {
                if ($connection !== $response) {
                    $connection->push(json_encode([
                        "client" => ZServer::getAddress($response),
                        "type" => "close"
                    ], JSON_OUTPUT_OPTIONS));
                }
            }
            return 'close';
        }
        echo date('[ Y-m-d H:i:s ] ') . ZServer::getAddress($response) . '：' . $frame->data . PHP_EOL;
        $response->push(json_encode([
            "client" => ZServer::getAddress($response),
            "data" => "欢迎光临",
            "type" => "welcome",
            "online" => count($server->connections)
        ], JSON_OUTPUT_OPTIONS));
        foreach ($server->connections as $connection) {
            if ($connection !== $response) {
                $connection->push(json_encode([
                    "client" => ZServer::getAddress($response),
                    "data" => $frame->data,
                    "type" => "data"
                ], JSON_OUTPUT_OPTIONS));
            }
        }
        return null;
    })
    ->start();