# DDNS 调度卡片与调度逻辑调整

## Goal

调整 DDNS 调度卡片的实例添加方式与调度规则表达，使管理员通过“添加实例”下拉单选将实例加入调度列表，加入后可移除，并可按实例配置小时活动时间段或按添加顺序进行按天轮换。

## Background / Known Context

- 项目使用 `template.html` 内嵌 Vue 负责前端设置页面，已有自定义 `app-select` 下拉组件。
- DDNS 调度配置当前显示在设置页的“DDNS 调度 / 定时切换”卡片中。
- 当前 UI 展示所有可调度实例，并通过“参与调度”开关控制 `scheduleFormEntries` 是否包含对应实例。
- 当前小时调度条目保存为 `{ instance_id, hour_start, hour_end }`；`DdnsScheduler::resolveHourSlotTargets()` 已支持 `hour_start > hour_end` 时跨日。
- 当前按天调度同时兼容显式 `day_start/day_end` 范围与 `days` 轮换；用户希望明确改为按实例添加顺序轮换，每个实例配置活动 `x` 天。
- 后端规则目前保存在 `ddns_schedule_rules` setting 中，`AliyunTrafficCheck::saveScheduleRulesFromFrontend()` 当前直接保存前端提交内容。
- `DdnsScheduler::resolveDayCycleTargets()` 已有基于 `days` 展开 cycle 的逻辑，可作为新按天轮换语义的核心。

## Assumptions

- 本任务只调整现有单条 DDNS 调度规则的卡片与逻辑，不新增多规则管理。
- “点击添加实例，然后通过下拉框单选加入”表示用户先选择一个尚未加入的实例，再点击添加，或点击添加后出现选择控件；最终每次只添加一个实例。
- 每个实例在同一调度规则内只允许出现一次。
- 小时选择使用 0-23 点作为开始时间，结束时间允许选择 0-23 点；结束时间等于开始时间视为无效。
- 按天轮换中 `活动 x 天` 存储为 `days`，按照 `entries` 顺序形成循环周期。

## Open Questions

- None.

## Requirements

- DDNS 调度卡片应从“所有实例 + 参与调度开关”改为“已添加实例列表 + 添加实例下拉单选”。
- 添加实例时，只能从未加入调度且未释放的可调度实例中选择一个实例。
- 已加入实例应可移除，移除后不再参与调度并重新出现在可添加选项中。
- 已加入实例应保留添加顺序；按天轮换必须使用该顺序作为轮换顺序。
- 小时调度模式下，每个已添加实例应配置开始时间和结束时间，两个时间均通过下拉框单选选择。
- 小时调度允许结束时间早于开始时间，表示跨日时间段。
- 小时调度不允许开始时间等于结束时间；保存时应提示错误并阻止提交。
- 按天轮换模式下，每个已添加实例应配置活动天数 `x`，调度器按添加顺序依次轮换，每个实例连续活动 `x` 天。
- 保存规则时应提交后端可执行的规范化 entries，避免重复实例和无效实例。
- 立即切换、暂停/启动、联动开关机、DNS 状态显示等既有功能应继续工作。
- 完成后必须通过 Docker 环境运行测试；Python Playwright 测试也应在 Docker 中执行。如测试需要访问外部网络，应配置 SOCKS5 代理，例如 `https_proxy="socks5://admin:123456@69.165.73.74:10801"`。

## Acceptance Criteria

- [ ] 管理员可以通过下拉框单选一个未添加实例，并点击添加加入 DDNS 调度列表。
- [ ] 已添加实例可以从调度列表移除，移除后保存规则不会再包含该实例。
- [ ] 小时调度可以为每个已添加实例选择开始时间和结束时间；当结束时间小于开始时间时，当前小时在跨日范围内可以正确命中该实例。
- [ ] 小时调度开始时间等于结束时间时，前端提示错误并阻止保存。
- [ ] 按天轮换按照 UI 中实例添加顺序轮换，并按每个实例配置的活动天数连续命中。
- [ ] 保存后重新加载页面或刷新状态时，已添加实例顺序、小时配置、活动天数配置保持一致。
- [ ] 未添加任何实例时保存规则会提示错误，不提交空调度。
- [ ] 现有 DDNS 联动开关机、立即切换、自动暂停和 DNS 状态查询入口不发生功能回退。
- [ ] Playwright 测试通过，且网络访问场景使用指定 SOCKS5 代理配置。

## Out of Scope

- 不新增多条 DDNS 调度规则管理。
- 不新增分钟级调度或非整点时间段。
- 不改变 Cloudflare DDNS provider 配置方式。
- 不调整账号/ECS 创建流程，除非与调度实例选择数据源存在直接冲突。

## Definition of Done

- 相关前端交互和后端调度逻辑完成。
- 至少运行 PHP 语法检查覆盖改动的 PHP 文件。
- 前端模板改动经过静态检查或人工路径核对。
- Docker 测试通过；Python Playwright 测试在 Docker 中执行，涉及外部网络时使用 `https_proxy=socks5://admin:123456@69.165.73.74:10801`。
- Trellis 计划文档完成并通过开始实现前的确认。

## Research References

- Repo inspection: `template.html`, `DdnsScheduler.php`, `AliyunTrafficCheck.php`, `ConfigManager.php`.
