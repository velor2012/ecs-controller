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
- `rules[].default_instance_id`: optional string; backend clears it when the instance is unknown or released.
- `rules[].entries`: non-empty array after backend filtering.
- Entry fields: `{ instance_id: string, duration: int >= 1 }`.
- `hour_cycle` interprets `duration` as hours and uses 3600-second units.
- `day_cycle` interprets `duration` as exact 24-hour days and uses 86400-second units.
- Valid schedulable instances are known ECS instances whose status is not `Released`.
- Legacy `hour_slot`, `hour_start`, `hour_end`, `days`, `day_start`, and `day_end` are not part of the active schedule contract.
- `get_schedule_rules` returns `next_switch_time` as `YYYY-MM-DD HH:MM:SS` for the first enabled calculable rule, or `null` when unavailable.

### 4. Validation & Error Matrix
- Missing or invalid `rules` array -> return failure from `saveScheduleRulesFromFrontend`.
- Entry with empty, unknown, duplicate, or released `instance_id` -> skip during normalization.
- No valid entries after filtering -> fail with `请至少添加一个有效的调度条目`.
- Invalid `schedule_type` -> normalize to `hour_cycle`.
- Invalid or out-of-range `duration` -> clamp into `1..365` before storing.
- Invalid `default_instance_id` -> store as empty string instead of failing.

### 5. Good/Base/Bad Cases
- Good: `schedule_type=hour_cycle`, entries `[{ instance_id: "i-a", duration: 8 }, { instance_id: "i-b", duration: 16 }]` rotates by 8-hour and 16-hour blocks from `anchor_at`.
- Base: `schedule_type=day_cycle`, entries `[{ instance_id: "i-a", duration: 2 }, { instance_id: "i-b", duration: 1 }]` resolves as `i-a, i-a, i-b` repeatedly in exact 24-hour units.
- Bad: all entries reference released/unknown instances; save must fail rather than persisting an enabled empty schedule.
- Bad: frontend writes legacy fixed hour fields; backend should normalize only the unified `{ instance_id, duration }` contract for new saves.

### 6. Tests Required
- Backend syntax: run `php -l AliyunTrafficCheck.php && php -l DdnsScheduler.php`.
- Save validation: assert no-valid-entry saves fail.
- Save normalization: assert unknown/released/default instances are removed or cleared.
- Save normalization: assert every save writes `anchor_at` and stores entries with `duration` only.
- Scheduler interpretation: assert `hour_cycle` and `day_cycle` share the same duration-cycle resolver with different unit seconds.
- Cycle interpretation: assert entries `A duration=2, B duration=1` resolve as `A, A, B, A, A, B...` for both schedule types.
- Next switch interpretation: assert `next_switch_time` is the end of the current entry duration block, not just the next single unit boundary.
- Frontend UI: assert add/remove instance flow and save payload use `duration` fields for both modes.

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
    { "instance_id": "i-a", "duration": 12 }
  ]
}
```

This means the instance runs for 12 hours from the saved rule anchor.

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
    { "instance_id": "i-a", "duration": 3 }
  ]
}
```

This means the instance runs for 3 exact 24-hour units from the saved rule anchor.
