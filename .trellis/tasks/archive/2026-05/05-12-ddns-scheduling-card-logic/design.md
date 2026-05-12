# DDNS 调度卡片与调度逻辑调整设计

## Technical Design

### Frontend Boundaries

- Work primarily in `template.html` inside the existing DDNS 调度 card and Vue setup state/functions.
- Reuse existing `app-select` for all single-select dropdown interactions.
- Replace the per-instance participation switch with:
  - an add row containing a single-select dropdown of available instances;
  - an Add button that appends the selected instance to `scheduleFormEntries`;
  - an added-entry list preserving `scheduleFormEntries` order;
  - a Remove button on each added entry.

### Data Contract

- Existing rule structure remains:
  - `schedule_type: 'hour_slot' | 'day_cycle'`
  - `default_instance_id`
  - `domain_prefix`
  - `entries`
- Hour entries remain `{ instance_id, hour_start, hour_end }`.
- Day-cycle entries should be normalized to `{ instance_id, days }` for new saves.
- Existing persisted day entries with `day_start/day_end` should continue to load, converted in the UI to an equivalent positive `days` value to avoid breaking existing configs.
- Hour entries with equal `hour_start` and `hour_end` are invalid and should be rejected before saving.

### Scheduler Behavior

- `resolveHourSlotTargets()` already supports cross-day ranges when `hour_start > hour_end`.
- Validate or normalize hour values before saving and/or processing to keep values in `0..23` if the UI switches end-time selection away from existing `1..24` semantics.
- `resolveDayCycleTargets()` should prefer the `days` contract for new entries and rotate in entry order by expanding each entry for its configured active-day count.
- Consider removing or de-prioritizing explicit `day_start/day_end` behavior only if compatibility risk is acceptable; otherwise keep compatibility for old stored rules.

### Compatibility

- Existing hour entries should continue loading.
- Existing day entries with `day_start/day_end` should not crash; they should display as a `days` value derived from range length.
- The stored JSON remains in `ddns_schedule_rules`, so no database migration is required.

### Validation

- Frontend should prevent duplicate entries by filtering add options and deduplicating on save.
- Frontend should reject no-entry saves.
- Frontend should reject invalid hour/day values before POST.
- Frontend should reject equal start/end hours with a user-visible error.
- Backend should be tolerant of malformed data because rules are user-configurable JSON from the authenticated UI.

## Rollout / Rollback

- Rollout is a template/PHP code change with no schema migration.
- Rollback is restoring previous UI and scheduler code; existing `days` entries would still be compatible with current scheduler logic.

## Test Environment

- Final verification must run through Docker.
- Browser verification must use the Python Playwright library/environment inside Docker.
- If Playwright or the application path needs external network access, configure Docker/test environment with `https_proxy=socks5://admin:123456@69.165.73.74:10801`.
