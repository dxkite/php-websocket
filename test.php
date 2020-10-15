<?php

require_once __DIR__ . '/websocket.php';

class EchoCsvHandler implements WebSocketHandler
{
    function open($conn, $header)
    {
        $this->log($conn->getPeer(), 'connected', $header);
    }

    function receive($conn, $frame)
    {
        if ($frame->isText()) {
            $this->log($conn->getPeer(), 'send echo', $conn->send($frame->payload()));
        } else {
            $handle = $frame->stream();
            while ($data = fgetcsv($handle, 0)) {
                if (is_array($data)) {
                    $result = $conn->send(json_encode($data));
                    $this->log($conn->getPeer(), "write process", $result);
                }    
            }
            $this->log($conn->getPeer(), "write", $conn->send('parse success'));
        }
        $conn->ping();
    }

    function close($conn)
    {
        $this->log($conn->getPeer(), 'closed');
    }

    protected function log(...$message)
    {
        $map = array_map('json_encode', $message);
        print(implode('|', $map) . PHP_EOL);
    }
}

(new WebSocketServer)->serve('0.0.0.0', 9999, new EchoCsvHandler);
