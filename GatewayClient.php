<?php
require __DIR__.'/vendor/autoload.php';

use GatewayWorker\Lib\Context;
use GatewayWorker\Protocols\GatewayProtocol;

class GatewayClient {
    public static array $registers = [];
    public static string $secretKey;

    /**
     * @return array
     * @throws Exception
     */
    public static function getGatewayAddressesFromRegister() : array
    {
        $register_addresses = static::$registers;

        if (empty($register_addresses)) {
            throw new \InvalidArgumentException("注册中心地址:".var_export($register_addresses, true));
        }
        $ret = false;
        foreach ($register_addresses as $register_address)
        {
            [$host, $port] = explode(':', $register_address, 2);

            $client = new Swoole\Client(SWOOLE_SOCK_TCP);
            $ret = $client->connect($host, $port, 5, 0); // 失败
            if ($ret) {
                break;
            }
        }
        if ($ret === false) {
            throw new Exception("连接注册中心服务器失败: {$register_address}, {$client->errCode}");
        }
        $buffer = ['event' => 'worker_connect', 'secret_key' => static::$secretKey];
        $client->send(\json_encode($buffer).PHP_EOL);
        $gateway_addresses = $client->recv();

        if (!$gateway_addresses = \json_decode(\trim($gateway_addresses), true)) {
            throw new Exception("获取网关服务地址失败: {$register_address}");
        }
        return $gateway_addresses['addresses'];
    }

    /**
     * @param int $group
     * @return array
     * @throws Exception
     */
    public static function getClientSessionsByGroup(int $group) : array {
        if ($group < 1) {
            return [];
        }

        // addresses
        $addresses = static::getGatewayAddressesFromRegister();

        $buffer = GatewayProtocol::$empty;
        $buffer['ext_data'] = $group;
        $buffer['cmd'] = GatewayProtocol::CMD_GET_CLIENT_SESSIONS_BY_GROUP;
        $send_buffer = static::$secretKey ?
            static::encodeSecretKey(). GatewayProtocol::encode($buffer) : GatewayProtocol::encode($buffer);

        $gateway_addresses = [];

        foreach ($addresses as $address) {
            $gateway_addresses[$address] = $send_buffer;
        }

        return  static::getBufferFromGateway($gateway_addresses);
    }

    /**
     * @param array $gateway_addresses
     * @return string
     */
    protected static function getBufferFromGateway(array $gateway_addresses) :array {

        $gateway_clients_read = [];
        $gateway_client_recv = [];
        $gateway_address_port = [];

        foreach ($gateway_addresses as $gateway_address => $send_buffer) {
            $client = new Swoole\Client(SWOOLE_SOCK_TCP);
            [$host, $port] = explode(':', $gateway_address, 2);
            var_dump($host);
            $ret = $client->connect($host, $port, 5, 0); // 失败
            if ($ret && $client->send($send_buffer)) {
                $sock = $client->sock;
                $gateway_clients_read[$sock] = $client;
                $gateway_client_recv[$sock] = '';
                $gateway_address_port[$sock][] = $host;
                $gateway_address_port[$sock][] = $port;
            }
        }

        $timeout = 5;
        $time_start = microtime(true);
        while(\count($gateway_clients_read) > 0) {
            $write = $except = [];
            $gateway_clients = $gateway_clients_read;
            if (swoole_client_select($gateway_clients, $write,
                    $except, 5) > 0) {
                /**
                 * @var $gateway_client \Swoole\Client
                 */
                foreach ($gateway_clients as $index => $gateway_client) {
                    $recv_buffer = $gateway_client->recv();
                    if ($recv_buffer !== "" && $recv_buffer !== false) {
                        $gateway_client_recv[$gateway_client->sock] .= $recv_buffer;
                        $recv_buffer_length = \strlen($gateway_client_recv[$gateway_client->sock]);
                        if (empty($gateway_client_recv_len[$gateway_client->sock]) && $recv_buffer_length >= 4) {
                            $buffer = $gateway_client_recv[$gateway_client->sock];
                            $gateway_client_recv_len[$gateway_client->sock] = 4+\current(\unpack('N', $buffer));
                        }

                        if (!empty($gateway_client_recv_len[$gateway_client->sock])  &&
                            $recv_buffer_length >= $gateway_client_recv_len[$gateway_client->sock]) {
                            unset(  $gateway_clients_read[$gateway_client->sock] );
                        }
                    } else {
                        unset(  $gateway_clients_read[$gateway_client->sock] );
                    }
                }
            }

            // 读取超时
            if (microtime(true) - $time_start > $timeout) {
                break;
            }
        }

        $client_id_group = [];
        foreach ($gateway_client_recv as $sock => $recv_buffer) {
            $local_ip   = $gateway_address_port[$sock][0];
            $local_port = $gateway_address_port[$sock][1];
            $client_id_group[$local_ip][$local_port] = \unserialize(\substr($recv_buffer, 4));
        }
        $group = [];
        foreach ($client_id_group as $local_ip => $data) {
            foreach($data as $local_port => $buffer) {
                if ($buffer) {
                    foreach ($buffer as $connection_id => $session_buffer) {
                        $client_id = \GatewayWorker\Lib\Context::addressToClientId($local_ip, $local_port, $connection_id);
                        $group[$client_id] = \unserialize($session_buffer);
                    }
                }
           }
        }
        return $group;
    }

    protected static function encodeSecretKey() : string
    {
        $buffer = GatewayProtocol::$empty;
        $buffer["body"] = \json_encode(["secret_key" => static::$secretKey]);
        $buffer["cmd"] = GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT;

        return GatewayProtocol::encode($buffer);
    }
}
