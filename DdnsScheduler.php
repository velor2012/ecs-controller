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

    public function getNextSwitchTime(array $rule): ?int
    {
        if (empty($rule['enabled']) || empty($rule['entries'])) {
            return null;
        }

        $scheduleType = $rule['schedule_type'] ?? 'hour_cycle';
        $unitSeconds = $scheduleType === 'day_cycle' ? 86400 : ($scheduleType === 'hour_cycle' ? 3600 : 0);
        $anchorAt = (int) ($rule['anchor_at'] ?? 0);
        if ($unitSeconds <= 0 || $anchorAt <= 0) {
            return null;
        }

        $segments = [];
        $cycleLength = 0;
        foreach ($rule['entries'] as $entry) {
            if (empty($entry['instance_id'])) {
                continue;
            }
            $duration = max(1, (int) ($entry['duration'] ?? 1));
            $segments[] = [
                'start' => $cycleLength,
                'end' => $cycleLength + $duration,
            ];
            $cycleLength += $duration;
        }
        if ($cycleLength <= 0) {
            return null;
        }

        $now = time();
        $elapsedUnits = intdiv(max(0, $now - $anchorAt), $unitSeconds);
        $cycleStartUnits = intdiv($elapsedUnits, $cycleLength) * $cycleLength;
        $position = $elapsedUnits % $cycleLength;
        foreach ($segments as $segment) {
            if ($position >= $segment['start'] && $position < $segment['end']) {
                return $anchorAt + (($cycleStartUnits + $segment['end']) * $unitSeconds);
            }
        }

        return null;
    }

    private function processRule(array $rule, bool $useDefaultFallback = true): bool
    {
        if (empty($rule['enabled']) || empty($rule['entries'])) {
            return false;
        }

        $scheduleType = $rule['schedule_type'] ?? 'hour_cycle';
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
        if ($scheduleType === 'hour_cycle') {
            $activeInstanceIds = $this->resolveCycleTargets($entries, (int) ($rule['anchor_at'] ?? 0), 3600);
        } elseif ($scheduleType === 'day_cycle') {
            $activeInstanceIds = $this->resolveCycleTargets($entries, (int) ($rule['anchor_at'] ?? 0), 86400);
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
            $shutdownMode = $action === 'stop'
                ? $this->configManager->get('shutdown_mode', 'KeepCharging')
                : 'KeepCharging';
            return $this->app->controlInstanceAction($account['id'], $action, $shutdownMode, true, true);
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

    private function resolveCycleTargets(array $entries, int $anchorAt, int $unitSeconds): array
    {
        if (empty($entries) || $unitSeconds <= 0) {
            return [];
        }

        $cycle = [];
        foreach ($entries as $entry) {
            if (empty($entry['instance_id'])) {
                continue;
            }
            $duration = max(1, (int) ($entry['duration'] ?? 1));
            for ($i = 0; $i < $duration; $i++) {
                $cycle[] = $entry['instance_id'];
            }
        }

        if (empty($cycle)) {
            return [];
        }

        if ($anchorAt <= 0) {
            $anchorAt = time();
        }
        $elapsedUnits = intdiv(max(0, time() - $anchorAt), $unitSeconds);
        $position = $elapsedUnits % count($cycle);
        return $this->normalizeInstanceIds([$cycle[$position]]);
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
