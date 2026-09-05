---
name: create-github-issue
description: >-
  Create or complete labeling on a GitHub issue using this repo's issue forms
  (Bug, Feature, Task, Config). Use when the user asks to open, file, or create
  an issue, ticket, bug report, feature request, task, or config change, or to
  set priority on an issue. Prefer templates under .github/ISSUE_TEMPLATE, set
  kind and priority: high|medium|low from analysis. Never apply or remove the
  ok label. After human ok, implementation uses the ship-issue skill.
---

# Create GitHub Issue

Open issues through this repo’s templates so kind, labels, and body shape stay consistent with `ship-issue`.

## Hard rules

- **Never add or remove the `ok` label.** Humans mark issues ready to implement.
- Always assign exactly one kind label: `bug` | `feature` | `task` | `config`.
- Always assign exactly one priority: `priority: high` | `priority: medium` | `priority: low`.
- **Labels:** only names in the [Label catalog](#label-catalog). When editing labels, only add or remove from that set — never invent names, never create missing labels in GitHub (report which are missing).
- Before creating, search open issues for a duplicate; if one exists, point to it instead of opening another.

## Label catalog

| Label | Role |
| --- | --- |
| `bug` | Kind |
| `feature` | Kind |
| `task` | Kind |
| `config` | Kind |
| `priority: high` | Priority |
| `priority: medium` | Priority |
| `priority: low` | Priority |
| `ok` | Human approval (agents never set this) |

## Templates

Files in `.github/ISSUE_TEMPLATE/`:

| Kind | Template file | GitHub issue type | Kind label |
| --- | --- | --- | --- |
| Bug | `bug.yml` | `Bug` | `bug` |
| Feature | `feature.yml` | `Feature` | `feature` |
| Task | `task.yml` | `Task` | `task` |
| Config | `configuration.yml` | use when org provides Config | `config` |

Chooser: `config.yml` (`blank_issues_enabled: false`). Config form file is `configuration.yml` because `config.yml` is reserved for the chooser.

Humans use the **New issue** template picker.

### Agents (API/MCP)

Mirror the form (you cannot submit the YAML UI):

1. Deduplicate against open issues.
2. Pick kind (bug / feature / task / config).
3. Title: template prefix (`[Bug]: `, `[Feature]: `, `[Task]: `, `[Config]: `) + short summary.
4. Set GitHub issue type when available (`Bug`, `Feature`, `Task`; Config when the org has it).
5. Set kind label + exactly one priority label. Do **not** set `ok`.
6. Body: same headings as the form; required fields non-empty; no extra sections.

### Body shapes

**Bug** — Summary (required), Steps to reproduce (required), Expected vs actual (optional).

**Feature** — Summary (required), Why (optional).

**Task** — Summary (required), Done when (optional).

**Config** — Summary (required), Current vs desired (optional).

## Priority

Choose from impact, urgency, and risk — not user tone alone:

| Label | When |
| --- | --- |
| `priority: high` | Blocks users or development; data loss / security / production breakage; no reasonable workaround. |
| `priority: medium` | Important and valuable but not blocking; workaround or limited scope. Default when unsure between high and low. |
| `priority: low` | Nice-to-have, cosmetic, speculative, or safely deferred. |

When touching an existing issue that lacks priority, set one using this table. Do not change an existing priority unless the user asks. Never add `ok` while doing so.

Report the priority and a one-line rationale after create (keep the issue body minimal).

## After create

- Return URL, number, priority, and rationale.
- State that implementation waits until a human adds `ok`.
- Implementation and PR: `ship-issue` (`<type>_<id>` branch, commits, PR, CI, handoff).
- Branch type mapping: `bug`, `feature`, `task`, `config` (lowercase).

## Done checklist

- [ ] Searched for duplicates before create
- [ ] Correct template/kind and title prefix
- [ ] Kind label + exactly one priority label; no `ok` added or removed
- [ ] Only catalog label names used; missing labels reported, not created
- [ ] Required body fields filled
- [ ] URL, number, and priority rationale returned
