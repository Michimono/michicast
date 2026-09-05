---
name: create-pull-request
description: >-
  Ship work on an issue via a feature branch, conventional commits, push, and
  open a PR for human review. Use when the user asks to create a PR, open a
  pull request, finish an issue, ship a change, prepare a branch for review,
  or commit and push work related to a GitHub issue. Covers never pushing to
  main, branch naming from issue type and id, secret and generated-file
  checks, past-tense commits, PR title conventions, CI fix loops, and handing
  off without merging.
---

# Create Pull Request

Ship one issue (or one focused change) from a clean branch through commits, push, PR, and CI — then stop for human review. Never merge.

## Hard rules

- Never commit, push, or merge to `main` (or `master`). Always work on a branch.
- Never merge the PR. After CI is green and brief review comments are posted, stop and hand off.
- Never force-push or skip hooks unless the user explicitly asks.
- Only commit when the user asked to commit / ship / open a PR (or equivalent).
- Treat `.gitignore` as the source of truth for ignored paths. Do not edit it lightly. If new work introduces a secret or sensitive file pattern that is not ignored, add the minimal ignore entry and call that out — do not invent broad ignore rules.
- Push only to `origin` on this repository, on the issue branch — never to `main`.

## Issue awareness

Issue types in this project: `bug`, `config`, `feature`, `task`.

- **When a GitHub issue exists for the work:** enforcement is mandatory. Derive type and id from that issue. Branch, commits context, PR title, and the issue-link comment must follow this skill.
- **When the repo has no issues yet / no issue for the work:** do not block. Still never push to `main`; create a branch, commit, push, and open a PR. Ask the user for `type` + `id` if they want the naming conventions applied early; otherwise use a short agreed branch name and omit issue fields from the PR title until an issue exists.

## Branch

1. Confirm current branch is not `main`/`master` for commits/pushes. If on `main`, create and switch before any commit.
2. When an issue is in play, name the branch:

```text
<issue_type>_<issue_id>
```

Examples: `feature_9`, `bug_23`, `task_5`, `config_12`.

3. Do all work for that issue on this branch only.

## Before every commit

1. **Secrets / sensitive data** — do not stage tokens, passwords, API keys, private credentials, auth dumps, or similar. Ordinary app identifiers (route keys, public slugs, migration timestamps) are fine.
2. **Ignored / generated paths** — do not stage paths covered by `.gitignore` (e.g. `vendor/`, `node_modules/`, `.env*`, caches, IDE folders, build artifacts). If unsure, check `.gitignore` and `git status`.
3. **New sensitive files** — if the change adds a new secret-bearing file type, ensure `.gitignore` covers it before committing; change `.gitignore` only when needed and keep the edit minimal.
4. **Tests** — all relevant tests must pass before committing. Prefer the narrowest set that covers the change (`php artisan test --compact` with a path or `--filter` when known); if the change is broad or the narrow set is unclear, run the full suite. Do not commit on failing tests.
5. **PHP style** — if PHP files changed, run `vendor/bin/pint --dirty --format agent` before committing.

## Commit message format

Always past tense for the subject and every body line.

```text
<short past-tense summary under ~250 characters>
<past-tense line describing one change>
<past-tense line describing one change>
...
```

Rules:

- Line 1: one-line summary of the commit (past tense: Added, Fixed, Changed, Removed, Updated, …).
- Each following line: exactly one change, also past tense, same verb style consistently.
- No bullet markers required; one change per line.

Example:

```text
Added create-pull-request agent skill
Created skill workflow for branch commit and PR handoff
Documented issue type verbs and title length limit
```

## Push

- Push to `origin` with upstream set on the issue branch only.
- Never push to `main`.

## Pull request

### Title

Format (whole title ≈ **60 characters or less**, including type, verb, ids, and description):

```text
<issue_type> <verb> #<issue_id> <Past description> (#<pr_id>)
```

| Issue type | Verb |
| --- | --- |
| `bug` | always `fix` |
| `feature` | always `resolve` |
| `task` | always `resolve` |
| `config` | `fix` if something was corrected; `resolve` if something was added or set up |

- `#<issue_id>` always has a leading `#`.
- Description is past tense, as short as possible so the full title stays ~60 characters.
- `(#<pr_id>)` always has a leading `#` inside parentheses.

GitHub does **not** put `(#<pr_id>)` in the title automatically.

1. Create the PR with the title **without** `(#<pr_id>)` (still keep the rest within the budget so the final form fits ~60 chars).
2. Read the new PR number.
3. Immediately update the title to append ` (#<pr_id>)`.

Example final title: `feature resolve #9 Added agent skills (#10)`

When no issue applies yet, omit `<issue_type> <verb> #<issue_id>` and use a short past-tense title, then still append ` (#<pr_id>)` after creation.

### Body

PR body = the commit messages for this branch (subjects and body lines), in order. Do not rewrite into a long essay. No `Closes` / `Fixes` trailer required — the `#<issue_id>` in the title is enough for GitHub to relate the PR; link the issue in a review comment instead.

### After open — CI loop

1. Never merge into `main`.
2. If there are no CI checks, treat CI as passed.
3. If checks exist, wait/inspect until they finish. On failure, fix from the CI feedback with a **new commit** on the same branch (same commit rules), push, and re-check.
4. Repeat until all checks pass (or none exist).

### Review comments then stop

Once the issue work is implemented, the PR exists, and CI is green (or absent):

1. Add brief PR comment(s) that help human review (what changed at a high level, how to verify). Do not over-explain.
2. Include a comment that links the issue with `#<issue_id>` when an issue was used.
3. Stop making commits. Hand off for human review. Return the PR URL.

## Workflow

1. Identify the issue (type + id) when issues apply; confirm base is `main` and create `<type>_<id>` if needed.
2. Implement only on that branch.
3. Pre-commit checks (secrets, `.gitignore`, tests, Pint when PHP changed).
4. Commit with past-tense subject + per-change body lines.
5. Push to `origin` on that branch.
6. Open PR; update title with `(#<pr_id>)`.
7. Body = commit messages from the branch.
8. CI fix loop until green (or no CI).
9. Brief review comments + issue link comment; stop; hand off.

## Done checklist

- [ ] Not on `main`/`master`; branch named `<type>_<id>` when an issue applies
- [ ] No secrets/sensitive files staged; `.gitignore` respected (updated only if a new secret path required it)
- [ ] Tests passed before each commit; Pint run when PHP changed
- [ ] Commits use past-tense subject and past-tense one-change body lines
- [ ] Pushed to `origin` on the issue branch only
- [ ] PR title matches convention and ends with `(#<pr_id>)`; length ~60 chars when an issue applies
- [ ] PR body is the branch commit messages
- [ ] CI green or no CI present
- [ ] Brief review comments posted (issue linked with `#<id>` when applicable)
- [ ] PR not merged; handed off with PR URL
