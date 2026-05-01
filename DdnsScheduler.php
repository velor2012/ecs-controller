<?php

require_once 'DdnsService.php';

class DdnsScheduler
{
    private $configManager;
    private $db;
    private $ddnsService;
    private $app;
    private $oldTargetForLog = null;

    public function __construct($configManager, $db, $ddnsService, $app = null)
    {
        $this->configManager = $configManager;
        $this->db = $db;
        $this->ddnsService = $ddnsService;
        $this->app = $app;
    }

    public function isEnabled(): bool
    {
        return $this->ddnsService && $this->ddnsService->isEnabled();
    }

    public function isLinkingEnabled(): bool
    {
        return $this->configManager->get('ddns_schedule_linking_enabled', '0') === '1' && $this->app !== null;
    }

    private function acquireLock()
    {
        $lockPath = __DIR__ . '/data/ddns-schedule.lock';
        $lock = @fopen($lockPath, 'c');
        if (!$lock) {
            return null;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    private function releaseLock($lock): void
    {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function processRuleForced(array $rule): bool
    {
        $lock = $this->acquireLock();
        if (!$lock) {
            $this->db->addLog('schedule', 'DDNS调度 立即切换失败: 无法获取进程锁');
            return false;
        }
        try {
            $oldState = $this->configManager->getScheduleState();
            $this->oldTargetForLog = $oldState['current_instance_id'] ?? null;
            $this->configManager->saveScheduleState([]);
            return $this->processRule($rule, false);
        } finally {
            $this->oldTargetForLog = null;
            $this->releaseLock($lock);
        }
    }

    public function checkAndSwitch(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $state = $this->configManager->getScheduleState();
        if (!empty($state['auto_schedule_paused'])) {
            return;
        }

        $lock = $this->acquireLock();
        if (!$lock) {
            $this->db->addLog('schedule', 'DDNS调度 跳过本轮: 无法获取进程锁');
            return;
        }

        try {
            $rules = $this->configManager->getScheduleRules();
            if (empty($rules)) {
                return;
            }

            foreach ($rules as $rule) {
                $this->processRule($rule);
            }
        } catch (Exception $e) {
            $this->db->addLog('schedule', 'DDNS调度 执行异常: ' . $e->getMessage());
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function processRule(array $rule, bool $useDefaultFallback = true): bool
    {
        if (empty($rule['enabled']) || empty($rule['entries'])) {
            return false;
        }

        $scheduleType = $rule['schedule_type'] ?? 'hour_slot';
        $entries = $rule['entries'];
        $domain = $this->configManager->get('ddns_domain', '');
        $prefix = trim((string)($rule['domain_prefix'] ?? ''));
        if ($prefix !== '' && $domain !== '') {
            $ruleDomain = strtolower($prefix . '.' . $domain);
        } else {
            $ruleDomain = !empty($rule['domain']) ? $rule['domain'] : $domain;
        }
        $defaultInstanceId = $rule['default_instance_id'] ?? '';

        $targetInstanceId = null;
        if ($scheduleType === 'hour_slot') {
            $targetInstanceId = $this->resolveHourSlotTarget($entries);
        } elseif ($scheduleType === 'day_cycle') {
            $targetInstanceId = $this->resolveDayCycleTarget($entries);
        }

        $state = $this->configManager->getScheduleState();
        $currentTarget = $state['current_instance_id'] ?? '';

        if ($targetInstanceId === null || $targetInstanceId === '') {
            if ($useDefaultFallback) {
                $targetInstanceId = $defaultInstanceId;
            } else {
                $excludeTarget = $this->oldTargetForLog ?? $currentTarget;
                $targetInstanceId = $this->resolveRandomFallback($entries, $excludeTarget, $defaultInstanceId);
            }
        } elseif (!$useDefaultFallback && $targetInstanceId === $this->oldTargetForLog) {
            $excludeTarget = $this->oldTargetForLog;
            $targetInstanceId = $this->resolveRandomFallback($entries, $excludeTarget, $defaultInstanceId);
        }

        if ($targetInstanceId === null || $targetInstanceId === '') {
            return false;
        }

        if ($targetInstanceId === $currentTarget) {
            return false;
        }

        $accounts = $this->configManager->getAccounts();
        $targetIp = '';
        $targetAccount = null;
        $currentAccount = null;
        $oldTargetId = $this->oldTargetForLog ?? $currentTarget;
        foreach ($accounts as $account) {
            $aid = $account['instance_id'] ?? '';
            if ($aid === $targetInstanceId) {
                $targetIp = $this->getEffectivePublicIp($account);
                $targetAccount = $account;
            }
            if ($oldTargetId !== '' && $aid === $oldTargetId) {
                $currentAccount = $account;
            }
        }

        if (empty($targetIp) || !filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 目标实例 {$targetInstanceId} 无有效公网IPv4，跳过");
            return false;
        }

        $linkingEnabled = $this->isLinkingEnabled();

        if ($linkingEnabled && $targetAccount !== null) {
            $targetStatus = $targetAccount['instance_status'] ?? '';
            if ($targetStatus === 'Stopped') {
                $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动开机: {$targetInstanceId}");
                $started = $this->controlInstance($targetAccount, 'start');
                if (!$started) {
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动开机失败 {$targetInstanceId}，切换中止");
                    return false;
                }
                $targetIp = $this->refreshInstanceIp($targetAccount);
                if (empty($targetIp)) {
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 开机后获取IP失败 {$targetInstanceId}，切换中止");
                    return false;
                }
                $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动开机成功: {$targetInstanceId}");
            }
        }

        $recordName = $this->buildSharedRecordName($ruleDomain);

        $result = $this->ddnsService->syncARecord($recordName, $targetIp);
        if (!empty($result['success'])) {
            $oldTarget = $this->oldTargetForLog ?? $currentTarget ?: '(无)';
            $stateData = [
                'current_instance_id' => $targetInstanceId,
                'last_switch_time' => time(),
            ];
            if ($this->oldTargetForLog !== null) {
                $stateData['auto_schedule_paused'] = true;
                $stateData['manual_switch_at'] = time();
            }
            $this->configManager->saveScheduleState($stateData);
            if ($scheduleType === 'day_cycle' && $this->configManager->getScheduleActivatedAt() === null) {
                $this->configManager->saveScheduleActivatedAt(time());
            }
            $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] from {$oldTarget} → {$targetInstanceId} ({$targetIp}) (success)");

            if ($linkingEnabled && $currentAccount !== null) {
                $currentStatus = $currentAccount['instance_status'] ?? '';
                if ($currentStatus === 'Running' || $currentStatus === 'Starting') {
                    $oldInstanceId = $currentAccount['instance_id'] ?? '';
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动关机: {$oldInstanceId}");
                    $stopped = $this->controlInstance($currentAccount, 'stop');
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动关机" . ($stopped ? '成功' : '失败') . ": {$oldInstanceId}");
                }
            }
            return true;
        } else {
            $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 切换失败: " . ($result['message'] ?? '未知错误'));
            return false;
        }
    }

    private function controlInstance(array $account, string $action): bool
    {
        if ($this->app === null) {
            return false;
        }
        try {
            return $this->app->controlInstanceAction($account['id'], $action);
        } catch (Exception $e) {
            $this->db->addLog('schedule', "DDNS调度 实例{$action}失败 [{$account['instance_id']}]: " . $e->getMessage());
            return false;
        }
    }

    private function refreshInstanceIp(array $account): string
    {
        try {
            sleep(5);
            $this->configManager->syncAccountGroups(true);
            $this->configManager->load();
            $synced = $this->configManager->getAccountById($account['id']);
            if ($synced) {
                return $this->getEffectivePublicIp($synced);
            }
        } catch (Exception $e) {
            $this->db->addLog('schedule', 'DDNS调度 刷新实例IP异常: ' . $e->getMessage());
        }
        return $this->getEffectivePublicIp($account);
    }

    private function resolveHourSlotTarget(array $entries): ?string
    {
        $currentHour = (int) date('G');

        foreach ($entries as $entry) {
            if (!isset($entry['hour_start'], $entry['hour_end'])) {
                continue;
            }
            $start = (int) $entry['hour_start'];
            $end = (int) $entry['hour_end'];

            if ($start <= $end) {
                if ($currentHour >= $start && $currentHour < $end) {
                    return $entry['instance_id'];
                }
            } else {
                if ($currentHour >= $start || $currentHour < $end) {
                    return $entry['instance_id'];
                }
            }
        }

        return null;
    }

    private function resolveDayCycleTarget(array $entries): ?string
    {
        if (empty($entries)) {
            return null;
        }

        $activatedAt = $this->configManager->getScheduleActivatedAt();
        $now = time();

        if ($activatedAt !== null) {
            $elapsedDays = (int) (($now - $activatedAt) / 86400);
        } else {
            $elapsedDays = (int) date('j') - 1;
        }

        $cycle = [];
        foreach ($entries as $entry) {
            $days = max(1, (int) ($entry['days'] ?? 1));
            for ($i = 0; $i < $days; $i++) {
                $cycle[] = $entry['instance_id'];
            }
        }

        if (empty($cycle)) {
            return null;
        }

        $position = $elapsedDays % count($cycle);
        return $cycle[$position < 0 ? 0 : $position];
    }

    /**
     * 强制切换的随机选择策略：
     * - 仅 1 个条目 → 默认实例
     * - 当前 = 默认实例 → 随机选非默认
     * - 多个条目 → 随机选非当前
     */
    private function resolveRandomFallback(array $entries, string $excludeTarget, string $defaultInstanceId): ?string
    {
        $instanceIds = [];
        foreach ($entries as $e) {
            $id = $e['instance_id'] ?? '';
            if ($id !== '') {
                $instanceIds[] = $id;
            }
        }

        if (empty($instanceIds)) {
            return null;
        }

        if (count($instanceIds) === 1) {
            $onlyId = $instanceIds[0];
            if ($excludeTarget !== '' && $onlyId === $excludeTarget) {
                return $defaultInstanceId !== '' && $defaultInstanceId !== $excludeTarget ? $defaultInstanceId : null;
            }
            return $defaultInstanceId !== '' && $defaultInstanceId !== $excludeTarget ? $defaultInstanceId : $onlyId;
        }

        if ($excludeTarget === $defaultInstanceId && $defaultInstanceId !== '') {
            $nonDefaults = array_values(array_filter($instanceIds, fn($id) => $id !== $defaultInstanceId));
            if (!empty($nonDefaults)) {
                return $nonDefaults[array_rand($nonDefaults)];
            }
        }

        $nonCurrent = array_values(array_filter($instanceIds, fn($id) => $id !== $excludeTarget));
        if (!empty($nonCurrent)) {
            return $nonCurrent[array_rand($nonCurrent)];
        }

        if ($defaultInstanceId !== '' && $defaultInstanceId !== $excludeTarget) {
            return $defaultInstanceId;
        }

        return null;
    }

    private function buildSharedRecordName(string $domain): string
    {
        return strtolower(trim($domain));
    }

    private function getEffectivePublicIp(array $account): string
    {
        if (($account['public_ip_mode'] ?? '') === 'eip') {
            $eip = trim((string) ($account['eip_address'] ?? ''));
            if ($eip !== '') {
                return $eip;
            }
        }
        return trim((string) ($account['public_ip'] ?? ''));
    }
}
