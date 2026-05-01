<?php
// Telegram 控制常驻轮询进程。主监控 cron 继续负责流量和实例巡检。

require_once 'AliyunTrafficCheck.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "仅支持 CLI 运行。";
    exit(1);
}

$app = new AliyunTrafficCheck();
$ref = new ReflectionClass($app);

$dbProp = $ref->getProperty('db');
$dbProp->setAccessible(true);
$db = $dbProp->getValue($app);

$configProp = $ref->getProperty('configManager');
$configProp->setAccessible(true);
$configManager = $configProp->getValue($app);

$service = new TelegramControlService($db, $configManager, $app);

while (true) {
    try {
        $service->processUpdatesWithTimeout(20);
    } catch (\Throwable $e) {
        $db->addLog('error', 'Telegram 控制常驻进程异常: ' . strip_tags($e->getMessage()));
        sleep(5);
    }
}
