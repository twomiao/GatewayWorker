<?php
require __DIR__.'/vendor/autoload.php';

define('GLOBAL_START', 1);

$start_file_workers = glob(__DIR__.'/Applications/Chat/start_*.php');

foreach ($start_file_workers as $start_file_worker) {
    require $start_file_worker;
}


$worker = new \Workerman\Worker();
$worker->name = 'gateway-client';
$worker->count = 1;

$worker->onWorkerStart = function (\Workerman\Worker $worker) {
   $counter = 30;
   while($counter-- > 0) {
       $sleep = 3;
       echo $worker->name."---- 等待 {$sleep}秒".PHP_EOL;
       \sleep($sleep);

       GatewayClient::$secretKey = 'abc123';
       GatewayClient::$registers = ['127.0.0.1:1236'];

       var_dump(GatewayClient::getClientSessionsByGroup(1));
   }
};

\Workerman\Worker::runAll();


