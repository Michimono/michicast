---
name: create-github-issue
description: >-
  Create or complete labeling on a GitHub issue using this repo's issue forms
  (Bug, Feature, Task, Config). Use when the user asks to open, file, or create
  an issue, ticket, bug report, feature request, task, or config change, or to
  set priority on an issue. Prefer templates under .github/ISSUE_TEMPLATE, set
  the GitHub issue type and one priority label (high, medium, low) from analysis.
  Never apply or remove the ok label. After human ok, implementation uses the
  ship-issue skill.
---

# Create GitHub Issue

Open issues through this repo’s templates so type, labels, and body shape stay consistent with `ship-issue`.

## Hard rules

- **Never add or remove the `ok` label.** Humans mark issues ready to implement.
- Always assign exactly one GitHub **issue type**: `Bug` | `Feature` | `Task` | `Config`.
- Always assign exactly one priority label: `high` | `medium` | `low`.
- **Labels:** only the names in the [Label catalog](#label-catalog). Kind is never a label — it lives on the issue type. Add or remove only catalog names — never invent a label, never create one in GitHub. If an expected label is missing, stop and report it.
- Before creating, search open issues for a duplicate; if one exists, point to it instead of opening another.

## Label catalog

| Label | Role |
| --- | --- |
| `high` | Priority |
| `medium` | Priority |
| `low` | Priority |
| `ok` | Human approval (agents never set this) |

This is the complete set. No other label may be applied. Do not use or recreate `bug`, `feature`, `task`, or `config` labels.

## Issue types

| Kind | Template file | GitHub issue type | Title prefix |
| --- | --- | --- | --- |
| Bug | `bug.yml` | `Bug` | `[Bug]: ` |
| Feature | `feature.yml` | `Feature` | `[Feature]: ` |
| Task | `task.yml` | `Task` | `[Task]: ` |
| Config | `configuration.yml` | `Config` | `[Config]: ` |

Chooser: `config.yml` (`blank_issues_enabled: false`). Config form file is `configuration.yml` because `config.yml` is reserved for the chooser.

Humans use the **New issue** template picker.

### Agents (API/MCP)

Mirror the form (you cannot submit the YAML UI):

1. Deduplicate against open issues.
2. Pick kind (Bug / Feature / Task / Config).
3. Title: template prefix + short summary.
4. Set the GitHub issue type to that kind (`Bug`, `Feature`, `Task`, or `Config`).
5. Set exactly one priority label. Do **not** set a kind label. Do **not** set `ok`.
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
| `high` | Blocks users or development; data loss / security / production breakage; no reasonable workaround. |
| `medium` | Important and valuable but not blocking; workaround or limited scope. Default when unsure between high and low. |
| `low` | Nice-to-have, cosmetic, speculative, or safely deferred. |

When touching an existing issue that lacks priority, set one using this table. Do not change an existing priority unless the user asks. Never add `ok` while doing so.

Report the priority and a one-line rationale after create (keep the issue body minimal).

## After create

- Return URL, number, priority, and rationale.
- State that implementation waits until a human adds `ok`.
- Implementation and PR: `ship-issue` (`<type>_<id>` branch, commits, PR, CI, handoff).
- Branch type mapping from the issue type: `Bug` → `bug`, `Feature` → `feature`, `Task` → `task`, `Config` → `config` (lowercase).

## Done checklist

- [ ] Searched for duplicates before create
- [ ] Correct template/kind, issue type, and title prefix
- [ ] Issue type set; exactly one priority label; no kind label; no `ok` added or removed
- [ ] Only catalog label names used; missing labels reported, not created
- [ ] Required body fields filled
- [ ] URL, number, and priority rationale returned
