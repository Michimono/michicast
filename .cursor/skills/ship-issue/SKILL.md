---
name: ship-issue
description: >-
  Implement an approved GitHub issue end-to-end: pick an ok-labeled issue,
  create or resume its branch, implement, run tests, commit, push, open or
  update a pull request, fix CI, and hand off for human review without merging.
  Use when the user asks to ship an issue, implement a ticket, pick the next
  issue, start work on an ok issue, create a branch, commit and push, open a
  PR, finish a feature/bug/task/config, prepare a pull request for review, or
  fix failing CI on an issue branch. Never push or merge to main; never apply
  or remove the ok label; only implement issues that already have ok.
---

# Ship Issue

Take one human-approved issue from branch through PR and CI, then stop for human review. Never merge.

## Hard rules

- **Base branch is always `main`.** Never commit, push, or merge to `main` (or `master`).
- **Only implement issues that have the `ok` label.** If the issue lacks `ok`, stop and say it needs human approval. Do not start a branch, commits, or PR. Do not treat a verbal “just do it” as approval unless the user explicitly waives the `ok` gate for that issue in the same message.
- **Never add or remove `ok`.** Humans own that label.
- **Labels:** only use names from the [Label catalog](#label-catalog). They all exist already. Add or remove only those names — never invent a label, never create one in GitHub. If an expected label is missing, stop and report it.
- Never merge the PR. After CI is green (or absent) and brief review comments are posted, hand off.
- Never force-push or skip hooks unless the user explicitly asks.
- Only commit when the user asked to ship / implement / commit / open a PR (or equivalent).
- Treat `.gitignore` as the source of truth for ignored paths. Edit it only when new secret-bearing paths require it, and keep that change minimal.
- Push only to `origin` on this repository, on the issue branch — never to `main`.

## Label catalog

Exact names only:

| Label | Role |
| --- | --- |
| `bug` | Kind |
| `feature` | Kind |
| `task` | Kind |
| `config` | Kind |
| `high` | Priority |
| `medium` | Priority |
| `low` | Priority |
| `ok` | Human approval to implement |

This is the complete set. No other label may be applied.

Rules: exactly one kind and exactly one priority on an issue when labels are complete. `ok` is optional until a human adds it. Agents never apply `ok`. Prefer higher priority when picking among `ok` issues (`high` → `medium` → `low`).

## Issue picking

1. If the user named an issue, use that id. Verify it has `ok` before any work.
2. If the user asked to pick work, list open issues with `ok`, prefer higher priority, confirm the choice if more than one is reasonable.
3. Derive `type` from GitHub issue type when set (`Bug` → `bug`, `Feature` → `feature`, `Task` → `task`). Otherwise use the kind label. For Config, use the `config` label (set issue type Config only when the org provides it).
4. If the issue is missing a priority label, set one per the `create-github-issue` skill analysis — still never add `ok`.
5. Creating new issues is out of scope here; use `create-github-issue`.

**Bootstrap (no issues in the repo yet):** if the repo has no issues at all, you may ship a focused change without an issue (still never push to `main`). Once issues exist, every implementation must be tied to an `ok` issue unless the user explicitly waives the gate.

## Branch

1. **Always branch from an up-to-date `main`.** Before creating a branch: `git checkout main`, then `git pull --ff-only`. Never branch from whatever happened to be checked out, and never branch from a stale `main` — the previous issue's PR may already be merged. Leave unrelated dirty files (for example a churned `package-lock.json`) unstaged rather than stashing or committing them.
2. If on `main`, create or switch before any commit.
3. Branch name when an issue applies:

```text
<issue_type>_<issue_id>
```

Examples: `feature_9`, `bug_23`, `task_5`, `config_12`.

4. **Resume if it already exists** (local or on `origin`). Do not invent a second branch for the same issue.
5. Do all work for that issue on this branch only.

## Before every commit

1. **Secrets** — do not stage tokens, passwords, API keys, private credentials, or auth dumps. Ordinary app identifiers are fine.
2. **Ignored / generated paths** — respect `.gitignore` (`vendor/`, `node_modules/`, `.env*`, caches, IDE folders, build artifacts, etc.).
3. **New sensitive paths** — if needed, add the minimal `.gitignore` entry and call it out.
4. **Tests** — relevant tests must pass. Prefer the narrowest set (`php artisan test --compact` with path or `--filter`); otherwise run the full suite. Do not commit on failures.
5. **PHP style** — if PHP changed, run `vendor/bin/pint --dirty --format agent`.
6. **Frontend** — if JS/CSS or `package.json` changed in a way that warrants it, run the project’s usual frontend check (e.g. build or tests) when configured; if none exist, note that and continue.

## Commit message format

Past tense for the subject and every body line.

```text
<short past-tense summary under ~250 characters>

<past-tense line describing one change>
...
```

- Subject: Added, Fixed, Changed, Removed, Updated, …
- **Leave a blank line after the subject.** Git treats the whole first paragraph as the subject, so body lines written directly under it collapse into one long subject in `git log` and in the PR.
- Each following line: one change, same past-tense style
- One logical chunk per commit; multiple commits on the branch are fine
- Pass the message with `git commit -F <file>` when it has a body; chained `-m` flags and shell quoting mangle it.

Example:

```text
Added ship-issue agent skill

Created workflow for branch commit PR and CI handoff
Documented issue verbs and title length limit
```

## Push

- `git push -u origin HEAD` (or equivalent) on the issue branch only.
- Never push to `main`.

## Pull request

### Title

Whole title ≈ **60 characters or less**:

```text
<issue_type> <verb> #<issue_id> <Past description> (#<pr_id>)
```

| Issue type | Verb |
| --- | --- |
| `bug` | `fix` |
| `feature` | `resolve` |
| `task` | `resolve` |
| `config` | `fix` if corrected; `resolve` if added or set up |

If over ~60 characters, **shorten the description first** — never drop type, verb, `#<issue_id>`, or `(#<pr_id>)`.

GitHub does not append `(#<pr_id>)` automatically:

1. If a PR for this branch/issue already exists, **update that PR** (do not open a duplicate).
2. Otherwise create the PR with the title **without** `(#<pr_id>)`, read the number, then update the title to append ` (#<pr_id>)`.
3. Base branch: `main`. Open as a ready PR (not draft) unless the user asks for draft.

Example: `feature resolve #9 Added agent skills (#10)`

Bootstrap without an issue: short past-tense title, then append ` (#<pr_id>)`.

### Body

PR body = this branch’s commit messages (subjects and body lines), in order. No long essay. No `Closes` / `Fixes` trailer — link the issue in a review comment with `#<issue_id>`.

### CI loop

1. Never merge into `main`.
2. No CI checks → treat as passed.
3. On failure → fix from CI feedback with a **new commit** (same commit rules), push, re-check.
4. Repeat until green (or no CI).

### Hand off

When implementation is done, PR exists, and CI is green (or absent):

1. Brief PR comment(s): what changed, how to verify. Do not over-explain.
2. Comment linking `#<issue_id>` when an issue was used.
3. Stop committing. Do not merge. Do not close the issue or alter `ok` after handoff — humans handle merge and issue lifecycle.
4. Return the PR URL.

## Workflow

1. Pick / confirm issue → verify `ok` → resolve type and id (backfill priority if missing).
2. Sync `main` (`git checkout main && git pull --ff-only`), then create or resume `<type>_<id>` from it.
3. Implement on that branch only.
4. Pre-commit checks → past-tense commit(s) → push to `origin`.
5. Open or update PR; ensure title ends with `(#<pr_id>)`.
6. CI fix loop until green (or no CI).
7. Brief review comments + issue link; hand off with PR URL.

## Done checklist

- [ ] Issue has `ok` (or explicit user waiver / true bootstrap); agent did not add or remove `ok`
- [ ] Labels used are only from the catalog; no new label names created
- [ ] Not on `main`; branched from a freshly pulled `main`; branch is `<type>_<id>` when issue-based; resumed existing branch/PR if present
- [ ] Commit subject is separated from its body by a blank line
- [ ] No secrets staged; `.gitignore` respected
- [ ] Tests passed before each commit; Pint when PHP changed
- [ ] Commits are past tense (subject + one-change body lines)
- [ ] Pushed to `origin` on the issue branch only
- [ ] PR title matches convention, ~60 chars, ends with `(#<pr_id>)`; body is commit messages
- [ ] CI green or no CI
- [ ] Brief review comments posted; PR not merged; PR URL returned
