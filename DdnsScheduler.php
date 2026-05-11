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

        $activeInstanceIds = [];
        if ($scheduleType === 'hour_slot') {
            $activeInstanceIds = $this->resolveHourSlotTargets($entries);
        } elseif ($scheduleType === 'day_cycle') {
            $activeInstanceIds = $this->resolveDayCycleTargets($entries);
        }
        $targetInstanceId = $activeInstanceIds[0] ?? null;

        $state = $this->configManager->getScheduleState();
        $currentTarget = $state['current_instance_id'] ?? '';

        if ($targetInstanceId === null || $targetInstanceId === '') {
            if ($useDefaultFallback) {
                $targetInstanceId = $defaultInstanceId;
                $activeInstanceIds = $targetInstanceId !== '' ? [$targetInstanceId] : [];
            } else {
                $excludeTarget = $this->oldTargetForLog ?? $currentTarget;
                $targetInstanceId = $this->resolveRandomFallback($entries, $excludeTarget, $defaultInstanceId);
                $activeInstanceIds = $targetInstanceId !== '' ? [$targetInstanceId] : [];
            }
        } elseif (!$useDefaultFallback && $targetInstanceId === $this->oldTargetForLog) {
            $excludeTarget = $this->oldTargetForLog;
            $targetInstanceId = $this->resolveRandomFallback($entries, $excludeTarget, $defaultInstanceId);
            $activeInstanceIds = $targetInstanceId !== '' ? [$targetInstanceId] : [];
        }

        if ($targetInstanceId === null || $targetInstanceId === '') {
            return false;
        }

        $activeInstanceIds = $this->normalizeInstanceIds($activeInstanceIds);

        if ($targetInstanceId === $currentTarget && !$this->isLinkingEnabled()) {
            return false;
        }

        $accounts = $this->configManager->getAccounts();
        $targetIp = '';
        $targetAccount = null;
        $scheduledInstanceIds = $this->getRuleInstanceIds($rule);
        $accountsByInstanceId = [];
        foreach ($accounts as $account) {
            $aid = $account['instance_id'] ?? '';
            if ($aid === '') {
                continue;
            }
            $accountsByInstanceId[$aid] = $account;
            if ($aid === $targetInstanceId) {
                $targetIp = $this->getEffectivePublicIp($account);
                $targetAccount = $account;
            }
        }

        $linkingEnabled = $this->isLinkingEnabled();

        if ($linkingEnabled) {
            foreach ($activeInstanceIds as $activeId) {
                $activeAccount = $accountsByInstanceId[$activeId] ?? null;
                if ($activeAccount === null) {
                    continue;
                }
                $activeStatus = $activeAccount['instance_status'] ?? '';
                if ($activeStatus === 'Stopped') {
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动开机: {$activeId}");
                    $started = $this->controlInstance($activeAccount, 'start');
                    if (!$started) {
                        $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动开机失败 {$activeId}，切换中止");
                        return false;
                    }
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动开机成功: {$activeId}");
                    if ($activeId === $targetInstanceId) {
                        $targetIp = $this->refreshInstanceIp($activeAccount);
                    }
                }
            }

            if ($targetAccount !== null && empty($targetIp)) {
                $targetIp = $this->refreshInstanceIp($targetAccount);
                if (empty($targetIp)) {
                    $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 开机后获取IP失败 {$targetInstanceId}，切换中止");
                    return false;
                }
            }
        }

        if (empty($targetIp) || !filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 目标实例 {$targetInstanceId} 无有效公网IPv4，跳过");
            return false;
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
            $activeText = implode(',', $activeInstanceIds);
            $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] from {$oldTarget} → {$targetInstanceId} ({$targetIp}) active=[{$activeText}] (success)");

            if ($linkingEnabled) {
                foreach ($scheduledInstanceIds as $scheduledId) {
                    if (in_array($scheduledId, $activeInstanceIds, true)) {
                        continue;
                    }
                    $inactiveAccount = $accountsByInstanceId[$scheduledId] ?? null;
                    if ($inactiveAccount === null) {
                        continue;
                    }
                    $inactiveStatus = $inactiveAccount['instance_status'] ?? '';
                    if ($inactiveStatus === 'Running' || $inactiveStatus === 'Starting') {
                        $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动关机: {$scheduledId}");
                        $stopped = $this->controlInstance($inactiveAccount, 'stop');
                        $this->db->addLog('schedule', "DDNS调度 [{$ruleDomain}] 联动关机" . ($stopped ? '成功' : '失败') . ": {$scheduledId}");
                    }
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
            return $this->app->controlInstanceAction($account['id'], $action, 'KeepCharging', true, true);
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

    private function resolveHourSlotTargets(array $entries): array
    {
        $currentHour = (int) date('G');
        $targets = [];

        foreach ($entries as $entry) {
            if (!isset($entry['hour_start'], $entry['hour_end'])) {
                continue;
            }
            $start = (int) $entry['hour_start'];
            $end = (int) $entry['hour_end'];

            if ($start <= $end) {
                if ($currentHour >= $start && $currentHour < $end) {
                    $targets[] = $entry['instance_id'] ?? '';
                }
            } else {
                if ($currentHour >= $start || $currentHour < $end) {
                    $targets[] = $entry['instance_id'] ?? '';
                }
            }
        }

        return $this->normalizeInstanceIds($targets);
    }

    private function resolveDayCycleTargets(array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $activatedAt = $this->configManager->getScheduleActivatedAt();
        $now = time();

        if ($activatedAt !== null) {
            $elapsedDays = (int) (($now - $activatedAt) / 86400);
        } else {
            $elapsedDays = (int) date('j') - 1;
        }

        if ($this->usesExplicitDayRanges($entries)) {
            $cycleLength = 0;
            foreach ($entries as $entry) {
                $cycleLength = max($cycleLength, (int) ($entry['day_end'] ?? 0));
            }
            if ($cycleLength <= 0) {
                return [];
            }

            $currentDay = ($elapsedDays % $cycleLength) + 1;
            $targets = [];
            foreach ($entries as $entry) {
                $start = max(1, (int) ($entry['day_start'] ?? 1));
                $end = max($start, (int) ($entry['day_end'] ?? $start));
                if ($currentDay >= $start && $currentDay <= $end) {
                    $targets[] = $entry['instance_id'] ?? '';
                }
            }
            return $this->normalizeInstanceIds($targets);
        }

        $cycle = [];
        foreach ($entries as $entry) {
            $days = max(1, (int) ($entry['days'] ?? 1));
            for ($i = 0; $i < $days; $i++) {
                $cycle[] = $entry['instance_id'];
            }
        }

        if (empty($cycle)) {
            return [];
        }

        $position = $elapsedDays % count($cycle);
        return $this->normalizeInstanceIds([$cycle[$position < 0 ? 0 : $position]]);
    }

    private function usesExplicitDayRanges(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (isset($entry['day_start']) || isset($entry['day_end'])) {
                return true;
            }
        }
        return false;
    }

    private function normalizeInstanceIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }
        return $normalized;
    }

    private function getRuleInstanceIds(array $rule): array
    {
        $ids = [];
        $defaultId = $rule['default_instance_id'] ?? '';
        if ($defaultId !== '') {
            $ids[] = $defaultId;
        }
        foreach ($rule['entries'] ?? [] as $entry) {
            $ids[] = $entry['instance_id'] ?? '';
        }
        return $this->normalizeInstanceIds($ids);
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
