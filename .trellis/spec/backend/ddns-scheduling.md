# DDNS Scheduling Contract

## Scenario: DDNS Schedule Rules

### 1. Scope / Trigger
- Trigger: DDNS scheduling is a cross-layer contract between `template.html`, `AliyunTrafficCheck.php`, stored config, and `DdnsScheduler.php`.
- Scope: frontend save payloads, backend normalization/validation, persisted `ddns_schedule_rules`, and scheduler interpretation.

### 2. Signatures
- Frontend action: `index.php?action=save_schedule_rules`
- Backend entry point: `AliyunTrafficCheck::saveScheduleRulesFromFrontend(array $data): array`
- Persisted config key: `ddns_schedule_rules`
- Scheduler consumer: `DdnsScheduler` reads normalized rules from config.

### 3. Contracts
- Request body must include `rules` as an object.
- `rules.enabled`: boolean-like value, stored as boolean.
- `rules.mode`: either `hour` or `day`; any other value is normalized to `hour`.
- `rules.default_instance_id`: optional string; backend clears it when the instance is unknown or released.
- `rules.entries`: non-empty array after backend filtering.
- Hour entry fields: `{ instance_id: string, hour_start: int 0..23, hour_end: int 0..23 }`.
- Day entry fields: `{ instance_id: string, days: int >= 1 }`.
- Legacy day fields `{ day_start, day_end }` remain readable for existing persisted config, but new saves should write `days`.
- Valid schedulable instances are known ECS instances whose status is not `Released`.

### 4. Validation & Error Matrix
- Missing or invalid `rules` object -> return failure from `saveScheduleRulesFromFrontend`.
- Entry with empty, unknown, duplicate, or released `instance_id` -> skip during normalization.
- No valid entries after filtering -> fail with `请至少添加一个有效的调度条目`.
- Hour entry with `hour_start === hour_end` -> fail with `开始时间不能等于结束时间`.
- Hour values outside `0..23` -> clamp into `0..23` before storing.
- Day entry with invalid `days` -> normalize to at least `1`.
- Invalid `default_instance_id` -> store as empty string instead of failing.

### 5. Good/Base/Bad Cases
- Good: `mode=hour`, one valid entry `{ instance_id: "i-a", hour_start: 22, hour_end: 6 }` stores and means cross-day active window.
- Base: `mode=day`, entries `[{ instance_id: "i-a", days: 2 }, { instance_id: "i-b", days: 1 }]` rotates in add order for 2 days on `i-a`, then 1 day on `i-b`.
- Bad: `mode=hour`, entry `{ instance_id: "i-a", hour_start: 8, hour_end: 8 }` must be rejected, not treated as all-day or empty.
- Bad: all entries reference released/unknown instances; save must fail rather than persisting an enabled empty schedule.

### 6. Tests Required
- Backend syntax: run `php -l AliyunTrafficCheck.php && php -l DdnsScheduler.php` in Docker.
- Save validation: assert equal start/end hours are rejected and no-valid-entry saves fail.
- Save normalization: assert unknown/released/default instances are removed or cleared.
- Scheduler interpretation: assert cross-day hour ranges match late-night and early-morning hours, while equal ranges are skipped defensively.
- Day rotation: assert `days` expands by entry order and legacy `day_start/day_end` still reads.
- Frontend UI: assert add/remove instance flow, equal-hour validation toast, and cross-day save payload.

### 7. Wrong vs Correct

#### Wrong
```json
{
  "mode": "hour",
  "entries": [
    { "instance_id": "i-a", "hour_start": 8, "hour_end": 8 }
  ]
}
```

This is ambiguous and must not be saved.

#### Correct
```json
{
  "mode": "hour",
  "entries": [
    { "instance_id": "i-a", "hour_start": 22, "hour_end": 6 }
  ]
}
```

This explicitly represents a cross-day active window.

#### Wrong
```json
{
  "mode": "day",
  "entries": [
    { "instance_id": "i-a", "day_start": 1, "day_end": 3 }
  ]
}
```

Do not write legacy range fields from new UI saves.

#### Correct
```json
{
  "mode": "day",
  "entries": [
    { "instance_id": "i-a", "days": 3 }
  ]
}
```

New day rotation saves use per-entry active day counts and preserve add order.
