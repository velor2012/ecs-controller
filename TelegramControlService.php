<?php

class TelegramControlService
{
    private $db;
    private $pdo;
    private $configManager;
    private $app;
    private $settings;

    public function __construct(Database $db, ConfigManager $configManager, $app)
    {
        $this->db = $db;
        $this->pdo = $db->getPdo();
        $this->configManager = $configManager;
        $this->app = $app;
        $this->settings = $configManager->getAllSettings();
    }

    public function processUpdates()
    {
        return $this->processUpdatesWithTimeout(1);
    }

    public function processUpdatesWithTimeout($timeout = 1)
    {
        if (!$this->isConfigured()) {
            return 0;
        }

        $lock = $this->acquireLock();
        if (!$lock) {
            return 0;
        }

        try {
            $this->cleanupExpiredTokens();
            $offset = ((int) $this->getState('last_update_id', '0')) + 1;
            $response = $this->api('getUpdates', [
                'offset' => $offset,
                'limit' => 20,
                'timeout' => max(0, (int) $timeout),
                'allowed_updates' => json_encode(['message', 'callback_query'])
            ]);

            if (!$response['ok']) {
                $message = $response['description'] ?? '未知错误';
                $this->db->addLog('error', 'Telegram 控制拉取消息失败: ' . $message);
                return 0;
            }

            $processed = 0;
            foreach (($response['result'] ?? []) as $update) {
                $updateId = (int) ($update['update_id'] ?? 0);
                if ($updateId > 0) {
                    // 先确认 offset，避免发送消息过程中被重启后重复响应旧指令。
                    $this->setState('last_update_id', (string) $updateId);
                }

                try {
                    if (isset($update['callback_query'])) {
                        $this->handleCallback($update['callback_query']);
                    } elseif (isset($update['message'])) {
                        $this->handleMessage($update['message']);
                    }
                    $processed++;
                } catch (\Exception $e) {
                    $this->db->addLog('error', 'Telegram 控制指令处理失败: ' . strip_tags($e->getMessage()));
                }
            }
            return $processed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function acquireLock()
    {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lock = @fopen($dir . '/telegram-control.lock', 'c');
        if (!$lock) {
            return null;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }

        return $lock;
    }

    private function isConfigured()
    {
        return trim((string) ($this->settings['notify_tg_token'] ?? '')) !== ''
            && trim((string) ($this->settings['notify_tg_chat_id'] ?? '')) !== '';
    }

    private function handleMessage(array $message)
    {
        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $userId = (string) ($from['id'] ?? '');
        if (!$this->isAllowed($chatId, $userId)) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $command = strtolower(preg_replace('/@.+$/', '', strtok($text, " \n\t") ?: ''));

        if (in_array($command, ['/traffic', '流量'], true)) {
            $this->sendMessage($chatId, $this->buildTrafficText(), $this->trafficKeyboard());
            return;
        }

        if (in_array($command, ['/instances', '实例'], true)) {
            $this->sendMessage($chatId, $this->buildInstancesText(1), $this->instancesKeyboard(1));
            return;
        }

        $this->sendMessage($chatId, $this->mainMenuText(), $this->mainMenuKeyboard());
    }

    private function handleCallback(array $callback)
    {
        $id = (string) ($callback['id'] ?? '');
        $from = $callback['from'] ?? [];
        $message = $callback['message'] ?? [];
        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $userId = (string) ($from['id'] ?? '');
        $messageId = (int) ($message['message_id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');

        if (!$this->isAllowed($chatId, $userId)) {
            $this->answerCallback($id, '没有权限执行该操作');
            return;
        }

        $parts = explode(':', $data);
        if (($parts[0] ?? '') !== 'm') {
            $this->answerCallback($id);
            return;
        }

        $action = $parts[1] ?? 'home';
        if ($action === 'home') {
            $this->answerCallback($id);
            $this->editMessage($chatId, $messageId, $this->mainMenuText(), $this->mainMenuKeyboard());
        } elseif ($action === 'help') {
            $this->answerCallback($id);
            $this->editMessage($chatId, $messageId, $this->helpText(), $this->mainMenuKeyboard());
        } elseif ($action === 'traffic') {
            $this->answerCallback($id, '正在刷新流量...');
            $this->refreshAllData();
            $this->editMessage($chatId, $messageId, $this->buildTrafficText(), $this->trafficKeyboard());
            return;
        } elseif ($action === 'list') {
            $this->answerCallback($id);
            $page = max(1, (int) ($parts[2] ?? 1));
            $this->editMessage($chatId, $messageId, $this->buildInstancesText($page), $this->instancesKeyboard($page));
        } elseif ($action === 'listrefresh') {
            $this->answerCallback($id, '正在刷新列表...');
            $page = max(1, (int) ($parts[2] ?? 1));
            $this->refreshAllData();
            $this->editMessage($chatId, $messageId, $this->buildInstancesText($page), $this->instancesKeyboard($page));
            return;
        } elseif ($action === 'inst') {
            $this->answerCallback($id);
            $accountId = (int) ($parts[2] ?? 0);
            $this->editMessage($chatId, $messageId, $this->buildInstanceDetailText($accountId), $this->instanceKeyboard($accountId));
        } elseif ($action === 'refresh') {
            $this->answerCallback($id, '正在刷新状态...');
            $accountId = (int) ($parts[2] ?? 0);
            if ($accountId > 0) {
                $this->app->refreshAccount($accountId);
            }
            $this->editMessage($chatId, $messageId, $this->buildInstanceDetailText($accountId), $this->instanceKeyboard($accountId));
            return;
        } elseif ($action === 'refreshall') {
            $this->answerCallback($id, '正在同步数据...');
            $this->refreshAllData();
            $this->editMessage($chatId, $messageId, $this->buildTrafficText(), $this->trafficKeyboard());
            return;
        } elseif ($action === 'start') {
            $this->answerCallback($id, '正在提交开机指令...');
            $accountId = (int) ($parts[2] ?? 0);
            $this->startInstance($chatId, $messageId, $accountId);
            return;
        } elseif ($action === 'stop') {
            $this->answerCallback($id, '正在提交停机指令...');
            $accountId = (int) ($parts[2] ?? 0);
            $this->stopInstance($chatId, $messageId, $accountId);
            return;
        } elseif ($action === 'release') {
            $this->answerCallback($id);
            $accountId = (int) ($parts[2] ?? 0);
            if (!$this->findInstance($accountId)) {
                $this->editMessage($chatId, $messageId, "释放失败：实例不存在或已被清理。", $this->mainMenuKeyboard());
                return;
            }
            $token = $this->createActionToken($userId, $chatId, 'release', $accountId);
            $this->editMessage($chatId, $messageId, $this->buildReleaseConfirmText($accountId), $this->releaseConfirmKeyboard($token, $accountId));
        } elseif ($action === 'confirm') {
            $this->answerCallback($id, '正在提交释放指令...');
            $token = (string) ($parts[2] ?? '');
            $this->confirmRelease($chatId, $messageId, $userId, $token);
            return;
        } elseif ($action === 'cancel') {
            $this->answerCallback($id);
            $token = (string) ($parts[2] ?? '');
            $this->useActionToken($token, $userId, $chatId, false);
            $this->editMessage($chatId, $messageId, "已取消释放操作。", $this->mainMenuKeyboard());
        } else {
            $this->answerCallback($id);
        }
    }

    private function isAllowed($chatId, $userId)
    {
        $configuredChatId = trim((string) ($this->settings['notify_tg_chat_id'] ?? ''));
        if ($configuredChatId === '' || $chatId !== $configuredChatId) {
            return false;
        }

        $allowed = $this->parseCsvIds($this->settings['notify_tg_allowed_user_ids'] ?? '');
        if (!empty($allowed)) {
            return in_array($userId, $allowed, true);
        }

        // 私聊场景下 chat_id 通常等于 from.id；群聊未配置白名单时不允许控制。
        return $chatId !== '' && $chatId[0] !== '-' && $chatId === $userId;
    }

    private function parseCsvIds($value)
    {
        $items = preg_split('/[\s,;，；]+/', trim((string) $value)) ?: [];
        return array_values(array_filter(array_map('trim', $items), function ($item) {
            return $item !== '';
        }));
    }

    private function mainMenuText()
    {
        return "🛡️ ECS 服务器管家\n\n请选择要执行的操作：";
    }

    private function helpText()
    {
        return "📘 使用说明\n\n"
            . "配置 Telegram 通知后，当前 Bot 默认支持远程控制。\n\n"
            . "可用功能：\n"
            . "📊 查看账号概览\n"
            . "🖥️ 查看实例列表和详情\n"
            . "🚀 对已停止实例一键开机\n"
            . "🗑️ 二次确认后释放实例\n\n"
            . "⚠️ 释放实例会进入后台安全队列，系统会继续处理停机、托管 EIP 回收、ECS 删除和 DDNS 清理。";
    }

    private function mainMenuKeyboard()
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 账号概览', 'callback_data' => 'm:traffic'],
                    ['text' => '🖥️ 实例列表', 'callback_data' => 'm:list:1']
                ],
                [
                    ['text' => '🔄 刷新数据', 'callback_data' => 'm:refreshall'],
                    ['text' => '📘 帮助说明', 'callback_data' => 'm:help']
                ]
            ]
        ];
    }

    private function trafficKeyboard()
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 刷新流量', 'callback_data' => 'm:traffic'],
                    ['text' => '🖥️ 查看实例', 'callback_data' => 'm:list:1']
                ],
                [
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'm:home']
                ]
            ]
        ];
    }

    private function buildTrafficText()
    {
        $config = $this->app->getConfigForFrontend();
        $accounts = $config['Accounts'] ?? [];
        if (empty($accounts)) {
            return "📊 账号概览\n\n暂无账号数据，请先在控制台添加账号。";
        }

        $lines = ["📊 账号概览"];
        foreach ($accounts as $account) {
            $used = (float) ($account['usageUsed'] ?? 0);
            $total = (float) ($account['maxTraffic'] ?? 0);
            $percent = (float) ($account['usagePercent'] ?? 0);
            $status = $percent >= 100 ? '已超量' : ($percent >= (float) ($config['traffic_threshold'] ?? 95) ? '接近阈值' : '正常');
            if (($account['trafficStatus'] ?? 'ok') !== 'ok') {
                $status = $account['trafficMessage'] ?: '流量同步异常';
            }

            $lines[] = "";
            $lines[] = "👤 账号：" . ($account['remark'] ?: '未命名账号');
            $lines[] = "📍 区域：" . $this->regionName($account['regionId'] ?? '');
            $lines[] = "📦 已用：" . $this->formatTraffic($used) . " / " . $this->formatTraffic($total);
            $lines[] = "📈 使用率：" . $percent . "%";
            $lines[] = $this->trafficStatusIcon($status) . " 状态：" . $status;
        }

        return implode("\n", $lines);
    }

    private function buildInstancesText($page)
    {
        $instances = $this->getInstances();
        if (empty($instances)) {
            return "🖥️ 实例列表\n\n暂无实例数据。";
        }

        $pageSize = 6;
        $totalPages = max(1, (int) ceil(count($instances) / $pageSize));
        $page = min(max(1, $page), $totalPages);
        $slice = array_slice($instances, ($page - 1) * $pageSize, $pageSize);

        $lines = ["🖥️ 实例列表 第 {$page}/{$totalPages} 页"];
        foreach ($slice as $inst) {
            $lines[] = "";
            $status = $inst['instanceStatus'] ?? '';
            $lines[] = "🖥️ " . ($inst['remark'] ?: $inst['instanceName'] ?: $inst['instanceId']);
            $lines[] = $this->statusIcon($status) . " 状态：" . $this->statusLabel($status);
            $lines[] = "📦 流量：" . $this->formatTraffic((float) ($inst['flow_used'] ?? 0)) . " / " . $this->formatTraffic((float) ($inst['flow_total'] ?? 0));
            $lines[] = "🌐 IP：" . (($inst['publicIp'] ?? '') ?: '-');
        }

        return implode("\n", $lines);
    }

    private function refreshAllData()
    {
        if (method_exists($this->app, 'getAllManagedInstances')) {
            $this->app->getAllManagedInstances(true);
            $this->configManager->load();
        }
    }

    private function instancesKeyboard($page)
    {
        $instances = $this->getInstances();
        $pageSize = 6;
        $totalPages = max(1, (int) ceil(count($instances) / $pageSize));
        $page = min(max(1, $page), $totalPages);
        $slice = array_slice($instances, ($page - 1) * $pageSize, $pageSize);

        $keyboard = [];
        foreach ($slice as $inst) {
            $label = $this->shortButtonText($this->statusIcon($inst['instanceStatus'] ?? '') . ' ' . ($inst['remark'] ?: $inst['instanceName'] ?: $inst['instanceId']) . ' / ' . $this->statusLabel($inst['instanceStatus'] ?? ''));
            $keyboard[] = [['text' => $label, 'callback_data' => 'm:inst:' . (int) $inst['accountId']]];
        }

        $pager = [];
        if ($page > 1) {
            $pager[] = ['text' => '⬅️ 上一页', 'callback_data' => 'm:list:' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $pager[] = ['text' => '下一页 ➡️', 'callback_data' => 'm:list:' . ($page + 1)];
        }
        if (!empty($pager)) {
            $keyboard[] = $pager;
        }

        $keyboard[] = [
            ['text' => '🔄 刷新列表', 'callback_data' => 'm:listrefresh:' . $page],
            ['text' => '🏠 返回主菜单', 'callback_data' => 'm:home']
        ];

        return ['inline_keyboard' => $keyboard];
    }

    private function buildInstanceDetailText($accountId)
    {
        $inst = $this->findInstance($accountId);
        if (!$inst) {
            return "🖥️ 实例详情\n\n实例不存在或已被清理。";
        }

        $status = $inst['instanceStatus'] ?? '';
        return "🖥️ 实例详情\n\n"
            . "🏷️ 备注：" . ($inst['remark'] ?: '-') . "\n"
            . "📍 区域：" . ($inst['regionName'] ?? $this->regionName($inst['regionId'] ?? '')) . "\n"
            . $this->statusIcon($status) . " 状态：" . $this->statusLabel($status) . "\n"
            . "🆔 实例 ID：" . ($inst['instanceId'] ?: '-') . "\n"
            . "🌐 公网 IP：" . (($inst['publicIp'] ?? '') ?: '-') . "\n"
            . "🔌 公网类型：" . (($inst['publicIpMode'] ?? '') === 'eip' ? 'EIP' : 'ECS 公网') . "\n"
            . "⚙️ 规格：" . (($inst['instanceType'] ?? '') ?: '-') . "\n"
            . "📦 出口流量：" . $this->formatTraffic((float) ($inst['flow_used'] ?? 0)) . " / " . $this->formatTraffic((float) ($inst['flow_total'] ?? 0)) . "\n"
            . "📈 使用率：" . ((float) ($inst['percentageOfUse'] ?? 0)) . "%\n"
            . "🚧 阈值：" . ((int) ($inst['threshold'] ?? 95)) . "%";
    }

    private function instanceKeyboard($accountId)
    {
        $inst = $this->findInstance($accountId);
        if (!$inst) {
            return ['inline_keyboard' => [[['text' => '🖥️ 返回实例列表', 'callback_data' => 'm:list:1']]]];
        }

        $status = $inst['instanceStatus'] ?? '';
        $locked = !empty($inst['operationLocked']) || in_array($status, ['Releasing', 'Released'], true);
        $keyboard = [];
        if (!$locked && $status === 'Stopped') {
            $keyboard[] = [['text' => '🚀 开机', 'callback_data' => 'm:start:' . (int) $accountId]];
        }
        if (!$locked && $status === 'Running') {
            $keyboard[] = [['text' => '🛑 停机', 'callback_data' => 'm:stop:' . (int) $accountId]];
        }
        if (!$locked && !in_array($status, ['Releasing', 'Released'], true)) {
            $keyboard[] = [['text' => '🗑️ 释放实例', 'callback_data' => 'm:release:' . (int) $accountId]];
        }
        $keyboard[] = [['text' => '🔄 刷新状态', 'callback_data' => 'm:refresh:' . (int) $accountId]];
        $keyboard[] = [
            ['text' => '🖥️ 返回实例列表', 'callback_data' => 'm:list:1'],
            ['text' => '🏠 返回主菜单', 'callback_data' => 'm:home']
        ];
        return ['inline_keyboard' => $keyboard];
    }

    private function buildReleaseConfirmText($accountId)
    {
        $inst = $this->findInstance($accountId);
        if (!$inst) {
            return "🗑️ 确认释放实例\n\n实例不存在或已被清理。";
        }

        $ttl = $this->confirmTtl();
        return "⚠️ 确认释放实例？\n\n"
            . "🖥️ 实例：" . ($inst['remark'] ?: $inst['instanceName'] ?: '-') . "\n"
            . "📍 区域：" . ($inst['regionName'] ?? $this->regionName($inst['regionId'] ?? '')) . "\n"
            . "🆔 实例 ID：" . ($inst['instanceId'] ?: '-') . "\n"
            . "🔌 公网类型：" . (($inst['publicIpMode'] ?? '') === 'eip' ? 'EIP' : 'ECS 公网') . "\n\n"
            . "🗑️ 释放后 ECS 会被删除，系统托管 EIP 和 DDNS 解析会同步清理，操作不可恢复。\n\n"
            . "⏱️ 请在 {$ttl} 秒内确认。";
    }

    private function releaseConfirmKeyboard($token, $accountId)
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '⚠️ 确认释放', 'callback_data' => 'm:confirm:' . $token],
                    ['text' => '取消', 'callback_data' => 'm:cancel:' . $token]
                ],
                [
                    ['text' => '🖥️ 返回实例详情', 'callback_data' => 'm:inst:' . (int) $accountId]
                ]
            ]
        ];
    }

    private function startInstance($chatId, $messageId, $accountId)
    {
        $inst = $this->findInstance($accountId);
        if (!$inst) {
            $this->editMessage($chatId, $messageId, "❌ 开机失败：实例不存在。", $this->mainMenuKeyboard());
            return;
        }

        if (($inst['instanceStatus'] ?? '') !== 'Stopped') {
            $this->editMessage($chatId, $messageId, "ℹ️ 当前实例不是已停机状态，无需开机。", $this->instanceKeyboard($accountId));
            return;
        }

        $success = $this->app->controlInstanceAction($accountId, 'start', 'KeepCharging', false);
        $this->db->addLog($success ? 'info' : 'error', "Telegram 控制开机" . ($success ? '成功' : '失败') . " [{$inst['remark']}] {$inst['instanceId']}");
        $this->editMessage(
            $chatId,
            $messageId,
            $success ? "🚀 开机指令已提交。\n\n🖥️ 实例：" . ($inst['remark'] ?: $inst['instanceId']) . "\n🟡 当前状态：启动中" : "❌ 开机失败，请查看系统日志。",
            $this->instanceKeyboard($accountId)
        );
    }

    private function stopInstance($chatId, $messageId, $accountId)
    {
        $inst = $this->findInstance($accountId);
        if (!$inst) {
            $this->editMessage($chatId, $messageId, "❌ 停机失败：实例不存在。", $this->mainMenuKeyboard());
            return;
        }

        if (($inst['instanceStatus'] ?? '') !== 'Running') {
            $this->editMessage($chatId, $messageId, "ℹ️ 当前实例不是运行中状态，无需停机。", $this->instanceKeyboard($accountId));
            return;
        }

        $success = $this->app->controlInstanceAction($accountId, 'stop', 'KeepCharging', false);
        $this->db->addLog($success ? 'info' : 'error', "Telegram 控制停机" . ($success ? '成功' : '失败') . " [{$inst['remark']}] {$inst['instanceId']}");
        $this->editMessage(
            $chatId,
            $messageId,
            $success ? "🛑 停机指令已提交。\n\n🖥️ 实例：" . ($inst['remark'] ?: $inst['instanceId']) . "\n🟠 当前状态：停机中" : "❌ 停机失败，请查看系统日志。",
            $this->instanceKeyboard($accountId)
        );
    }

    private function confirmRelease($chatId, $messageId, $userId, $token)
    {
        $record = $this->useActionToken($token, $userId, $chatId, true);
        if (!$record) {
            $this->editMessage($chatId, $messageId, "⏱️ 释放确认已失效，请重新发起释放操作。", $this->mainMenuKeyboard());
            return;
        }

        $accountId = (int) $record['account_id'];
        $inst = $this->findInstance($accountId);
        $success = $this->app->deleteInstanceAction($accountId);
        $label = $inst ? ($inst['remark'] ?: $inst['instanceId']) : ('实例 #' . $accountId);
        $this->db->addLog($success ? 'warning' : 'error', "Telegram 提交释放" . ($success ? '成功' : '失败') . " [{$label}]");
        $this->editMessage(
            $chatId,
            $messageId,
            $success
                ? "🗑️ 释放指令已提交。\n\n🖥️ 实例：{$label}\n后台释放队列已接管，会继续处理停机、托管 EIP 回收、ECS 删除和 DDNS 清理。"
                : "❌ 释放指令提交失败，请查看系统日志。",
            $this->mainMenuKeyboard()
        );
    }

    private function getInstances()
    {
        $status = $this->app->getStatusForFrontend(true);
        $items = $status['data'] ?? [];
        usort($items, function ($a, $b) {
            return strcmp(($a['regionName'] ?? '') . ($a['remark'] ?? ''), ($b['regionName'] ?? '') . ($b['remark'] ?? ''));
        });
        return $items;
    }

    private function findInstance($accountId)
    {
        foreach ($this->getInstances() as $inst) {
            if ((int) ($inst['accountId'] ?? 0) === (int) $accountId) {
                return $inst;
            }
        }
        return null;
    }

    private function createActionToken($userId, $chatId, $action, $accountId, array $payload = [])
    {
        $token = bin2hex(random_bytes(8));
        $stmt = $this->pdo->prepare("
            INSERT INTO telegram_action_tokens
                (token, user_id, chat_id, action, account_id, payload, expires_at, used_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
        ");
        $now = time();
        $stmt->execute([
            $token,
            (string) $userId,
            (string) $chatId,
            $action,
            (int) $accountId,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $now + $this->confirmTtl(),
            $now
        ]);
        return $token;
    }

    private function useActionToken($token, $userId, $chatId, $markUsed)
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM telegram_action_tokens
            WHERE token = ?
                AND user_id = ?
                AND chat_id = ?
                AND used_at = 0
                AND expires_at >= ?
            LIMIT 1
        ");
        $stmt->execute([(string) $token, (string) $userId, (string) $chatId, time()]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            return null;
        }

        $update = $this->pdo->prepare("UPDATE telegram_action_tokens SET used_at = ? WHERE id = ?");
        $update->execute([time(), (int) $record['id']]);

        return $record;
    }

    private function cleanupExpiredTokens()
    {
        $stmt = $this->pdo->prepare("DELETE FROM telegram_action_tokens WHERE expires_at < ? OR (used_at > 0 AND used_at < ?)");
        $stmt->execute([time() - 3600, time() - 86400]);
    }

    private function getState($key, $default = '')
    {
        $stmt = $this->pdo->prepare("SELECT value FROM telegram_bot_state WHERE key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    private function setState($key, $value)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO telegram_bot_state (key, value)
            VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ");
        $stmt->execute([$key, $value]);
    }

    private function sendMessage($chatId, $text, array $keyboard = null)
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text
        ];
        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return $this->api('sendMessage', $payload);
    }

    private function editMessage($chatId, $messageId, $text, array $keyboard = null)
    {
        if ($messageId <= 0) {
            return $this->sendMessage($chatId, $text, $keyboard);
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text
        ];
        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        $response = $this->api('editMessageText', $payload);
        if (!$response['ok']) {
            return $this->sendMessage($chatId, $text, $keyboard);
        }
        return $response;
    }

    private function answerCallback($callbackId, $text = '')
    {
        if ($callbackId === '') {
            return;
        }
        $payload = ['callback_query_id' => $callbackId];
        if ($text !== '') {
            $payload['text'] = $text;
        }
        $this->api('answerCallbackQuery', $payload);
    }

    private function api($method, array $payload = [])
    {
        $token = trim((string) ($this->settings['notify_tg_token'] ?? ''));
        $proxyType = $this->settings['notify_tg_proxy_type'] ?? 'none';
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        if ($proxyType === 'custom' && !empty($this->settings['notify_tg_proxy_url'])) {
            $url = rtrim($this->settings['notify_tg_proxy_url'], '/') . "/bot{$token}/{$method}";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($proxyType === 'socks5') {
            $proxyIp = trim((string) ($this->settings['notify_tg_proxy_ip'] ?? ''));
            $proxyPort = trim((string) ($this->settings['notify_tg_proxy_port'] ?? ''));
            if ($proxyIp !== '' && $proxyPort !== '') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                curl_setopt($ch, CURLOPT_PROXY, "{$proxyIp}:{$proxyPort}");
                $proxyUser = $this->settings['notify_tg_proxy_user'] ?? '';
                $proxyPass = $this->settings['notify_tg_proxy_pass'] ?? '';
                if ($proxyUser !== '' || $proxyPass !== '') {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxyUser}:{$proxyPass}");
                }
            }
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'description' => '网络请求错误: ' . $error];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'description' => "接口返回异常 {$httpCode}: " . (string) $raw];
        }
        return $decoded;
    }

    private function confirmTtl()
    {
        return max(30, (int) ($this->settings['notify_tg_confirm_ttl'] ?? 60));
    }

    private function shortButtonText($text)
    {
        $text = trim((string) $text);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 28) {
            return mb_substr($text, 0, 25, 'UTF-8') . '...';
        }
        return $text;
    }

    private function formatTraffic($value)
    {
        $value = (float) $value;
        if ($value <= 0) {
            return '0 MB';
        }
        if ($value < 1) {
            return round($value * 1024, 2) . ' MB';
        }
        return round($value, 2) . ' GB';
    }

    private function statusLabel($status)
    {
        $map = [
            'Running' => '运行中',
            'Starting' => '启动中',
            'Stopping' => '停机中',
            'Stopped' => '已停止',
            'Pending' => '创建中',
            'Releasing' => '释放中',
            'Released' => '已释放',
            'Unknown' => '未知'
        ];
        return $map[$status] ?? ($status ?: '未知');
    }

    private function statusIcon($status)
    {
        $map = [
            'Running' => '🟢',
            'Starting' => '🟡',
            'Stopping' => '🟠',
            'Stopped' => '🔴',
            'Pending' => '🟡',
            'Releasing' => '🗑️',
            'Released' => '⚫',
            'Unknown' => '⚪'
        ];
        return $map[$status] ?? '⚪';
    }

    private function trafficStatusIcon($status)
    {
        if (strpos($status, '超量') !== false || strpos($status, '异常') !== false) {
            return '🔴';
        }
        if (strpos($status, '接近') !== false) {
            return '🟠';
        }
        return '🟢';
    }

    private function regionName($regionId)
    {
        $map = [
            'cn-hongkong' => '中国香港',
            'ap-southeast-1' => '新加坡',
            'ap-northeast-1' => '日本（东京）',
            'us-west-1' => '美国（硅谷）',
            'us-east-1' => '美国（弗吉尼亚）',
            'cn-hangzhou' => '华东 1（杭州）',
            'cn-shanghai' => '华东 2（上海）',
            'cn-beijing' => '华北 2（北京）',
            'cn-shenzhen' => '华南 1（深圳）'
        ];
        return $map[$regionId] ?? ($regionId ?: '-');
    }
}
