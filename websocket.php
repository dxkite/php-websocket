<?php

class HybiFrame implements Countable, JsonSerializable
{
    protected $finish = 1;
    protected $rsv    = [0, 0, 0];
    protected $opcode = 0;

    /**
     * @var string|null
     */
    protected $mask    = null;
    protected $length  = 0;
    protected $socket  = null;
    protected $payload = null;

    const TEXT_FRAME   = 0x1;
    const BINARY_FRAME = 0x2;

    const CLOSE_FRAME  = 0x8;
    const PING_FRAME   = 0x9;
    const PONG_FRAME   = 0xA;

    /**
     * @param int $opcode
     * @param string $data
     * @param string $mask
     * @param bool $finish
     * @param int[] $rsv
     */
    public function __construct($opcode, $data = '', $mask = null, $finish = true, $rsv = [0, 0, 0])
    {
        $this->opcode  = $opcode;
        $this->payload = $data;
        $this->mask    = $mask;
        $this->finish  = $finish;
        $this->rsv     = $rsv;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->encode();
    }

    /**
     * 编码数据
     *
     * @return string
     */
    public function encode()
    {
        $header = '';
        $b      = 0;
        if ($this->finish) {
            $b |= 0x80;
        }
        for ($i = 0; $i < 3; $i++) {
            if ($this->rsv[$i]) {
                $j = 6 - $i;
                $b |= 1 << $j;
            }
        }
        $b            |= $this->opcode;
        $header       .= chr($b);
        $length       = strlen($this->payload);
        $data         = $this->payload;
        $lengthFields = 0;
        if ($this->mask !== null) {
            $b = 0x80;
        } else {
            $b = 0;
        }
        if ($length < 126) {
            $b |= $length;
        } elseif ($length < 65536) {
            $b            |= 126;
            $lengthFields = 2;
        } else {
            $b            |= 127;
            $lengthFields = 8;
        }
        $header .= chr($b);
        for ($i = 0; $i < $lengthFields; $i++) {
            $j      = ($lengthFields - $i - 1) * 8;
            $b      = chr(($length >> $j) & 0xff);
            $header .= $b;
        }
        if ($this->mask !== null) {
            $header .= $this->mask;
            for ($i = 0; $i < strlen($data); $i++) {
                $data[$i] = $data[$i] ^ $this->mask[$i % 4];
            }
        }
        return $header . $data;
    }

    /**
     * 解析数据
     *
     * @param resource $socket
     * @return HybiFrame|null
     */
    public static function parseFrame($socket)
    {
        $frame = new HybiFrame(0, '');
        $size  = @socket_recv($socket, $buffer, 2, MSG_WAITALL);
        if ($size <= 0) {
            return null;
        }
        $b1            = ord($buffer[0]);
        $frame->finish = (($b1 >> 7) & 1);
        $rsv1          = (($b1 >> 6) & 1);
        $rsv2          = (($b1 >> 5) & 1);
        $rsv3          = (($b1 >> 4) & 1);
        $frame->opcode = $b1 & 0x0F;
        $frame->rsv    = [$rsv1, $rsv2, $rsv3];
        $maskKey       = null;
        $b2            = ord($buffer[1]);
        $mask          = (($b2 >> 7) & 1) == 1;
        $payloadLength = $b2 & 0x7F;
        if ($payloadLength === 126) {
            if (@socket_recv($socket, $extendPayloadLength, 2, MSG_WAITALL) === 2) {
                $length = static::decodeNumber($extendPayloadLength);
            } else {
                return null;
            }
        } elseif ($payloadLength === 127) {
            if (@socket_recv($socket, $extendPayloadLength, 8, MSG_WAITALL) === 8) {
                $length = static::decodeNumber($extendPayloadLength);
            } else {
                return null;
            }
        } else {
            $length = $payloadLength;
        }
        $frame->length = $length;
        $maskKey       = null;
        if ($mask) {
            if (@socket_recv($socket, $maskKey, 4, MSG_WAITALL) === 4) {
                $frame->mask = $maskKey;
            } else {
                return null;
            }
        }
        $frame->socket = HybiFrameReader::NewReader($frame, $socket);
        return $frame;
    }

    protected static function decodeNumber($number)
    {
        $num = 0;
        $len = strlen($number);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($number[$i]);
            if ($len == 8 && $i == 0) {
                $b &= 0x7F;
            }
            $num = $num * 256 + $b;
        }
        return $num;
    }

    public function count()
    {
        return $this->length;
    }

    public function mask()
    {
        return $this->mask;
    }

    public function isText()
    {
        return $this->opcode === self::TEXT_FRAME;
    }

    public function isBinary()
    {
        return $this->opcode === self::BINARY_FRAME;
    }

    /**
     * 作为二进制读取
     *
     * @return false|resource
     */
    public function stream()
    {
        return $this->socket;
    }

    /**
     * 读取全部的数据
     */
    public function payload()
    {
        $text = '';
        while (!feof($this->stream())) {
            if ($read = fread($this->socket, 1024)) {
                $text .= $read;
            }
        }
        return $text;
    }

    public function getOpcode()
    {
        return $this->opcode;
    }

    public function jsonSerialize()
    {
        return [
            'finish' => $this->finish,
            'rsv'    => $this->rsv,
            'opcode' => $this->opcode,
            'mask'   => bin2hex($this->mask),
            'length' => $this->length,
        ];
    }
}

class HybiFrameReader
{
    const STREAM_NAME = 'hybi-reader';
    public    $context;
    protected $position = 0;
    /**
     * @var HybiFrame
     */
    protected $frame;
    protected $length = 0;
    protected $mask   = null;
    protected $socket = null;

    function stream_open($path, $mode, $options, &$opened_path)
    {
        $context        = stream_context_get_options($this->context)[HybiFrameReader::STREAM_NAME];
        $this->frame    = $context['frame'];
        $this->socket   = $context['socket'];
        $this->length   = $this->frame->count();
        $this->mask     = $this->frame->mask();
        $this->position = 0;
        return true;
    }

    function stream_read($count)
    {
        // 数据读取完全
        if ($this->position >= $this->length) {
            return false;
        }
        if ($count > ($this->length - $this->position)) {
            $count = $this->length - $this->position;
        }
        $bytes = @socket_recv($this->socket, $data, $count, 0);
        if ($bytes === false) {
            return false;
        }
        // 掩码解码
        if ($this->mask !== null) {
            for ($i = 0; $i < strlen($data); $i++) {
                $data[$i] = $data[$i] ^ $this->mask[$this->position % 4];
                $this->position++;
            }
        } else {
            $this->position += strlen($data);
        }
        return $data;
    }

    function stream_tell()
    {
        return $this->position;
    }

    function stream_eof()
    {
        return $this->position === $this->length;
    }

    public static function register()
    {
        if (!in_array(self::STREAM_NAME, stream_get_wrappers())) {
            stream_register_wrapper(self::STREAM_NAME, self::class);
        }
    }

    public static function NewReader($frame, $socket)
    {
        static::register();
        return fopen(self::STREAM_NAME . '://', 'r', false, stream_context_create([self::STREAM_NAME => [
            'frame'  => $frame,
            'socket' => $socket,
        ]]));
    }
}

/**
 * Class WebSocketConn
 * 客户端连接
 */
class WebSocketConn
{
    /**
     * @var string
     */
    protected $peer;
    /**
     * @var resource
     */
    protected $socket;

    /**
     * Conn constructor.
     * @param $peer
     * @param $r
     * @param $w
     */
    public function __construct($peer, $socket)
    {
        $this->peer   = $peer;
        $this->socket = $socket;
    }

    public function ping()
    {
        $frame   = new HybiFrame(HybiFrame::PING_FRAME);
        $payload = $frame->encode();
        return @socket_write($this->socket, $payload) > 0;
    }

    public function close()
    {
        $frame   = new HybiFrame(HybiFrame::CLOSE_FRAME);
        $payload = $frame->encode();
        return @socket_write($this->socket, $payload) > 0;
    }

    /**
     * @param string $message
     * @return bool
     */
    public function send($message)
    {
        if ($message instanceof HybiFrame) {
            $data = strval($message->encode());
        } else {
            $data = strval((new HybiFrame(HybiFrame::TEXT_FRAME, $message))->encode());
        }
        return @socket_write($this->socket, $data) === strlen($data);
    }

    /**
     * @param string $message
     * @return bool
     */
    public function sendBlob($message)
    {
        return $this->send(new HybiFrame(HybiFrame::BINARY_FRAME, $message));
    }

    /**
     * @return string
     */
    public function getPeer()
    {
        return $this->peer;
    }
}

interface WebSocketHandler
{
    /**
     * @param WebSocketConn $conn
     * @param array $header
     * @return mixed
     */
    function open($conn, $header);

    /**
     * @param WebSocketConn $conn
     * @param HybiFrame $frame
     * @return mixed
     */
    function receive($conn, $frame);

    /**
     * @param WebSocketConn $conn
     * @return mixed
     */
    function close($conn);
}


class WebSocketServer
{
    protected $sockets   = [];
    protected $master;
    protected $handshake = [];

    /**
     * @param string $addr
     * @param string $port
     * @param WebSocketHandler $handler
     */
    public function serve($addr, $port, $handler)
    {

        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() failed");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1) or die("socket_option() failed");
        socket_bind($this->master, $addr, $port) or die("socket_bind() failed");
        socket_listen($this->master, 255) or die("socket_listen() failed");
        $this->sockets[] = $this->master;
        $this->log('listen', $addr, $port);
        while (true) {
            $read   = $this->sockets;
            $write  = array();
            $except = array();
            socket_select($read, $write, $except, null);
            foreach ($read as $socket) {
                if ($socket == $this->master) {
                    $client   = socket_accept($this->master);
                    $clientId = $this->getPeerId($client);
                    if ($client !== false) {
                        $this->sockets[$clientId]   = $client;
                        $this->handshake[$clientId] = false;
                        $this->log('accept new', $clientId);
                    }
                } else {
                    $clientId = $this->getPeerId($socket);
                    // 没有握手
                    if ($this->handshake[$clientId] == false) {
                        $this->log($clientId, 'handshake');
                        $headers = $this->handshake($socket);
                        $conn    = new WebSocketConn($clientId, $socket);
                        $handler->open($conn, $headers);
                        $this->handshake[$clientId] = true;
                    } else {
                        $this->receive($clientId, $socket, $handler);
                    }
                }
            }
        }
    }

    /**
     * @param $socket
     * @return string
     */
    protected function getPeerId($socket)
    {
        socket_getpeername($socket, $_addr, $_port);
        return $_addr . ':' . $_port;
    }

    /**
     * @param $clientId
     * @param $socket
     * @param WebSocketHandler $handler
     */
    protected function receive($clientId, $socket, $handler)
    {
        $frame = HybiFrame::parseFrame($socket);
        if ($frame === null) {
            $this->log('receive unknown frame');
            $this->closeClient($clientId);
            return;
        }
        $this->log('receive', $frame);
        $opcode = $frame->getOpcode();
        $conn   = new WebSocketConn($clientId, $socket);
        switch ($opcode) {
            case HybiFrame::TEXT_FRAME:
            case HybiFrame::BINARY_FRAME:
                $handler->receive($conn, $frame);
                break;
            case HybiFrame::PING_FRAME:
                $this->log('receive ping');
                $frame   = new HybiFrame(HybiFrame::PONG_FRAME);
                $payload = $frame->encode();
                @socket_write($socket, $payload);
                break;
            case HybiFrame::PONG_FRAME:
                $this->log('receive pong');
                break;
            case HybiFrame::CLOSE_FRAME:
                $handler->close($conn);
                $this->closeClient($clientId);
                break;
            default:
                $this->log('receive unknown opcode', $opcode);
                $this->closeClient($clientId);
        }
    }

    /**
     * @param $clientId
     */
    protected function closeClient($clientId)
    {
        if (array_key_exists($clientId, $this->sockets)) {
            unset($this->sockets[$clientId]);
        }
    }

    /**
     * TODO full header parser
     * @param $socket
     * @return array
     */
    protected function handshake($socket)
    {
        @socket_recv($socket, $buffer, 2048, 0);
        list($r, $h, $origin, $swk, $proto) = $this->getheaders($buffer);
        $upgrade = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" .
            "Upgrade: WebSocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Version: 13\r\n".
            "Sec-WebSocket-Origin: " . $origin . "\r\n" .
            "Sec-WebSocket-Accept: " . $this->genSecAccept($swk) . "\r\n";
        if (!empty($proto)) {
            $upgrade .= "Sec-WebSocket-Protocol: " . $proto . "\r\n";
        }
        $upgrade .= "\r\n";
        @socket_write($socket, $upgrade, strlen($upgrade));
        return array($r, $h, $origin, $swk, $proto);
    }

    function getHeaders($req)
    {
        $r = $h = $o = $swk = $proto = null;
        if (preg_match("/GET (.*) HTTP/", $req, $match)) {
            $r = $match[1];
        }
        if (preg_match("/Host: (.*)\r\n/", $req, $match)) {
            $h = $match[1];
        }
        if (preg_match("/Origin: (.*)\r\n/", $req, $match)) {
            $o = $match[1];
        }
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $req, $match)) {
            $swk = $match[1];
        }
        if (preg_match("/Sec-WebSocket-Protocol: (.*)\r\n/", $req, $match)) {
            $proto = $match[1];
        }
        return array($r, $h, $o, $swk, $proto);
    }

    protected function genSecAccept($key)
    {
        $magic = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        $sha   = sha1($key . $magic, true);
        return base64_encode($sha);
    }

    protected function log(...$message)
    {
        $map = [date('Y-m-d H:i:s')];
        $map = array_map('json_encode', $message);
        $log = implode('|', $map) . PHP_EOL;
        print($log);
    }
}
