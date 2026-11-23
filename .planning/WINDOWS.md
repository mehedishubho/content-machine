---
schema_version: 1
open_count: 3
waived_count: 0
fixed_count: 0
total_count: 3
last_updated: 2026-11-23T11:04:13.286Z
---

# Broken Windows Ledger

> Cross-phase defect register. With `workflow.windows_enforce` enabled, `/gsd-ship` blocks while `open_count > 0`.
> Waive with `gsd-tools windows waive <id> "<reason>"` (reason required).
> Mark fixed with `gsd-tools windows fixed <id>`.

| id | phase | kind | file | line | description | status | reason | recorded_at | resolved_at |
|----|-------|------|------|------|-------------|--------|--------|-------------|-------------|
| 1 | 01 | deviation | app/Http/Requests/ObservabilityPeriodRequest.php |  | Plan file path vs PSR-4 namespace collision fixed: namespace aligned to App\\Http\\Requests (plan path kept) | open |  | 2026-11-23T11:04:04.330Z |  |
| 2 | 01 | deviation | resources/css/studio.css |  | Task 3 grid change (UI-SPEC: dash-grid auto-fit minmax(240px,1fr)) lives in studio.css which the plan's Task 3 file list omitted; folded into the Task 3 commit | open |  | 2026-11-23T11:04:12.957Z |  |
| 3 | 01 | deviation | app/Http/Controllers/DashboardController.php |  | publishHealth prop named publish_health per the codebase's snake_case Inertia prop convention (human_id, created_at, period_label); plan prose used camelCase shorthand | open |  | 2026-11-23T11:04:13.286Z |  |

````json
[
  {
    "id": 1,
    "kind": "deviation",
    "phase": "01",
    "file": "app/Http/Requests/ObservabilityPeriodRequest.php",
    "line": null,
    "description": "Plan file path vs PSR-4 namespace collision fixed: namespace aligned to App\\Http\\Requests (plan path kept)",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-11-23T11:04:04.330Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 2,
    "kind": "deviation",
    "phase": "01",
    "file": "resources/css/studio.css",
    "line": null,
    "description": "Task 3 grid change (UI-SPEC: dash-grid auto-fit minmax(240px,1fr)) lives in studio.css which the plan's Task 3 file list omitted; folded into the Task 3 commit",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-11-23T11:04:12.957Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 3,
    "kind": "deviation",
    "phase": "01",
    "file": "app/Http/Controllers/DashboardController.php",
    "line": null,
    "description": "publishHealth prop named publish_health per the codebase's snake_case Inertia prop convention (human_id, created_at, period_label); plan prose used camelCase shorthand",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-11-23T11:04:13.286Z",
    "resolved_at": null,
    "milestone": null
  }
]
````
