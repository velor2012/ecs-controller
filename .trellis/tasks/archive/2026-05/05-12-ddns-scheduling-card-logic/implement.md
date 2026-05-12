# DDNS 调度卡片与调度逻辑调整实施计划

## Implementation Checklist

- [ ] Load project-specific development guidance before editing.
- [ ] Update DDNS schedule card markup in `template.html` to use add/select/remove interaction.
- [ ] Add Vue state for pending selected instance and available add options.
- [ ] Replace toggle/get-entry helpers with explicit add/remove helpers while preserving entry order.
- [ ] Update hour options and validation for start/end dropdown semantics, rejecting equal start/end values.
- [ ] Update day-cycle entry normalization and save payload to use `days`.
- [ ] Adjust `DdnsScheduler.php` day-cycle and hour value handling if needed for the finalized time semantics.
- [ ] Add backend normalization in `AliyunTrafficCheck::saveScheduleRulesFromFrontend()` if frontend-only validation is insufficient.
- [ ] Verify PHP syntax and key UI data-flow paths.
- [ ] Run Docker-based verification with SOCKS5 proxy configured for network access.

## Validation

- `php -l DdnsScheduler.php`
- `php -l AliyunTrafficCheck.php`
- `php -l ConfigManager.php` if changed
- Manual/static path check for `template.html` Vue bindings and returned setup values.
- Run subsequent tests through Docker rather than directly on the host.
- Pass `https_proxy="socks5://admin:123456@69.165.73.74:10801"` into Docker/test commands when network access is required.
- Python Playwright command/script should run inside Docker using the repo's available setup.

## Review Gates

- Review planning artifacts before running `task.py start`.
