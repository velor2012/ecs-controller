# DDNS Scheduling Contract

## Scenario: DDNS Schedule Rules

### 1. Scope / Trigger
- Trigger: DDNS scheduling is a cross-layer contract between `template.html`, `AliyunTrafficCheck.php`, stored config, and `DdnsScheduler.php`.
- Scope: frontend save payloads, backend normalization/validation, persisted `ddns_schedule_rules`, and scheduler interpretation.

### 2. Signatures
- Frontend action: `index.php?action=save_schedule_rules`
- Frontend read action: `index.php?action=get_schedule_rules`
- Backend entry point: `AliyunTrafficCheck::saveScheduleRulesFromFrontend(array $data): array`
- Persisted config key: `ddns_schedule_rules`
- Scheduler consumer: `DdnsScheduler` reads normalized rules from config.

### 3. Contracts
- Request body must include `rules` as an array.
- `rules[].enabled`: boolean-like value, stored as boolean.
- `rules[].schedule_type`: either `hour_cycle` or `day_cycle`; any other value is normalized to `hour_cycle`.
- `rules[].anchor_at`: backend-owned Unix timestamp; every save resets it to current server time.
- `rules[].entries`: non-empty array after backend filtering.
- Entry fields: `{ instance_id: string, duration: int >= 1 }`.
- `hour_cycle` interprets `duration` as hours and uses 3600-second units.
- `day_cycle` interprets `duration` as exact 24-hour days and uses 86400-second units.
- Valid schedulable instances are known ECS instances whose status is not `Released`.
- Account-level scheduled start/stop has higher priority than DDNS scheduling; entries whose account has scheduled start or stop enabled remain saved but are ignored by scheduler target resolution.
- Scheduler execution requires at least two eligible entries after account-schedule filtering; fewer than two eligible entries is a no-op and `next_switch_time` is `null`.
- Monitor keep-alive DDNS exemptions must use the same eligible-entry filtering so account scheduled start/stop instances are not treated as DDNS-managed inactive targets.
- Legacy `hour_slot`, `hour_start`, `hour_end`, `days`, `day_start`, and `day_end` are not part of the active schedule contract.
- `get_schedule_rules` returns `next_switch_time` as `YYYY-MM-DD HH:MM:SS` for the first enabled calculable rule, or `null` when unavailable.

### 4. Validation & Error Matrix
- Missing or invalid `rules` array -> return failure from `saveScheduleRulesFromFrontend`.
- Entry with empty, unknown, duplicate, or released `instance_id` -> skip during normalization.
- No valid entries after filtering -> fail with `请至少添加一个有效的调度条目`.
- Invalid `schedule_type` -> normalize to `hour_cycle`.
- Invalid or out-of-range `duration` -> clamp into `1..365` before storing.
- `default_instance_id` is not part of new saves and must be ignored if present in legacy persisted rules.

### 5. Good/Base/Bad Cases
- Good: `schedule_type=hour_cycle`, entries `[{ instance_id: "i-a", duration: 8 }, { instance_id: "i-b", duration: 16 }]` rotates by 8-hour and 16-hour blocks from `anchor_at`.
- Base: `schedule_type=day_cycle`, entries `[{ instance_id: "i-a", duration: 2 }, { instance_id: "i-b", duration: 1 }]` resolves as `i-a, i-a, i-b` repeatedly in exact 24-hour units.
- Base: one eligible entry after account-schedule filtering is not enough for DDNS rotation and should no-op.
- Base: entries with account-level scheduled start/stop enabled are visible in UI but ignored by scheduler target resolution.
- Bad: all entries reference released/unknown instances; save must fail rather than persisting an enabled empty schedule.
- Bad: frontend writes legacy fixed hour fields; backend should normalize only the unified `{ instance_id, duration }` contract for new saves.

### 6. Tests Required
- Backend syntax: run `php -l AliyunTrafficCheck.php && php -l DdnsScheduler.php`.
- Save validation: assert no-valid-entry saves fail.
- Save normalization: assert unknown/released instances are removed.
- Save normalization: assert every save writes `anchor_at` and stores entries with `duration` only.
- Scheduler interpretation: assert `hour_cycle` and `day_cycle` share the same duration-cycle resolver with different unit seconds.
- Scheduler interpretation: assert fewer than two eligible entries no-op.
- Scheduler interpretation: assert account scheduled start/stop entries are ignored by DDNS scheduling and linking.
- Monitor interpretation: assert account scheduled start/stop entries are not included in DDNS keep-alive skip lists.
- Cycle interpretation: assert entries `A duration=2, B duration=1` resolve as `A, A, B, A, A, B...` for both schedule types.
- Next switch interpretation: assert `next_switch_time` is the end of the current entry duration block, not just the next single unit boundary.
- Frontend UI: assert add/remove instance flow, conflict warnings, and save payload use `duration` fields for both modes without `default_instance_id`.

### 7. Wrong vs Correct

#### Wrong
```json
{
  "schedule_type": "hour_slot",
  "entries": [
    { "instance_id": "i-a", "hour_start": 8, "hour_end": 20 }
  ]
}
```

Fixed clock windows are no longer the active hourly scheduling contract.

#### Correct
```json
{
  "schedule_type": "hour_cycle",
  "entries": [
    { "instance_id": "i-a", "duration": 12 },
    { "instance_id": "i-b", "duration": 12 }
  ]
}
```

This means each eligible instance runs for 12 hours from the saved rule anchor.

#### Wrong
```json
{
  "schedule_type": "day_cycle",
  "entries": [
    { "instance_id": "i-a", "days": 3 }
  ]
}
```

Do not write legacy day fields from new UI saves.

#### Correct
```json
{
  "schedule_type": "day_cycle",
  "entries": [
    { "instance_id": "i-a", "duration": 3 },
    { "instance_id": "i-b", "duration": 1 }
  ]
}
```

This means `i-a` runs for 3 exact 24-hour units and `i-b` runs for 1 exact 24-hour unit from the saved rule anchor.
