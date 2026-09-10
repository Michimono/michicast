---
name: ship-issue
description: >-
  Implement an approved GitHub issue end-to-end: pick an ok-labeled issue,
  create or resume its branch, implement, run tests, commit, push, open or
  update a pull request, run the CI checks locally, and hand off for human
  review without merging. Use when the user asks to ship an issue, implement a
  ticket, pick the next issue, start work on an ok issue, create a branch,
  commit and push, open a PR, finish a feature/bug/task/config, prepare a pull
  request for review, or fix a failing CI check on an issue branch. Never push
  or merge to main; never apply or remove the ok label; only implement issues
  that already have ok.
---

# Ship Issue

Take one human-approved issue from branch through PR, with the CI checks run locally, then stop for human review. Never merge.

## Hard rules

- **Base branch is always `main`.** Never commit, push, or merge to `main` (or `master`).
- **Only implement issues that have the `ok` label.** If the issue lacks `ok`, stop and say it needs human approval. Do not start a branch, commits, or PR. Do not treat a verbal “just do it” as approval unless the user explicitly waives the `ok` gate for that issue in the same message.
- **Never add or remove `ok`.** Humans own that label.
- **Labels:** only use names from the [Label catalog](#label-catalog). They all exist already. Add or remove only those names — never invent a label, never create one in GitHub. If an expected label is missing, stop and report it. Kind is never a label; it lives on the GitHub issue type.
- **Run the CI checks locally; never wait on GitHub.** Reproduce every job in `.github/workflows/ci.yml` on this machine before pushing, and hand off as soon as they pass. Do not poll the PR checks, sleep for them, or block the handoff on them. See [Local CI](#local-ci).
- Never merge the PR. After the local CI checks pass and brief review comments are posted, hand off.
- Never force-push or skip hooks unless the user explicitly asks.
- Only commit when the user asked to ship / implement / commit / open a PR (or equivalent).
- Treat `.gitignore` as the source of truth for ignored paths. Edit it only when new secret-bearing paths require it, and keep that change minimal.
- Push only to `origin` on this repository, on the issue branch — never to `main`.
- **New tests for application changes.** If the issue changes application code (runtime behaviour), add or update tests that cover the change and its important failure modes. Do not commit or hand off until those tests exist and pass. A green existing suite is not enough. Follow the `testing-best-practices` skill. Issues that only change documentation, agent skills, rules, or other non-runtime files are exempt when no application behaviour changed.

## Label catalog

Exact names only:

| Label | Role |
| --- | --- |
| `high` | Priority |
| `medium` | Priority |
| `low` | Priority |
| `ok` | Human approval to implement |

This is the complete set. No other label may be applied. Do not use or recreate `bug`, `feature`, `task`, or `config` labels.

Rules: every issue has exactly one GitHub issue type (`Bug`, `Feature`, `Task`, or `Config`) and exactly one priority label when labels are complete. `ok` is optional until a human adds it. Agents never apply `ok`. Prefer higher priority when picking among `ok` issues (`high` → `medium` → `low`).

## Issue picking

1. If the user named an issue, use that id. Verify it has `ok` before any work.
2. If the user asked to pick work, list open issues with `ok`, prefer higher priority, confirm the choice if more than one is reasonable.
3. Derive branch `type` from the GitHub issue type: `Bug` → `bug`, `Feature` → `feature`, `Task` → `task`, `Config` → `config`. If the issue type is missing, stop and set it before branching — do not invent a kind label.
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
4. **Tests** — if the issue changed application behaviour, the branch must include new or updated tests that exercise those cases and their important failure modes. Passing the unchanged suite is not enough. Then run the narrowest set that covers the change (`php artisan test --compact` with path or `--filter`); otherwise run the full suite. Do not commit on failures or on missing coverage.
5. **PHP style** — if PHP changed, run `vendor/bin/pint --dirty --format agent`.
6. **Frontend** — if JS/CSS or `package.json` changed in a way that warrants it, run the build as described in [Local CI](#local-ci).

## Commit message format

Decide the PR title **before** the first commit. The first commit's subject is that title, exactly. Later commits on the same branch keep a short past-tense subject.

Past tense for the subject and every body line.

First commit:

```text
<issue_type> <verb> #<issue_id> <Past description>

<past-tense line describing one change>
...
```

Later commits:

```text
<short past-tense summary under ~250 characters>

<past-tense line describing one change>
...
```

- First-commit subject: the PR title (`<issue_type> <verb> #<issue_id> <Past description>`), still ≈ 60 characters. Do not append `(#<pr_id>)`.
- Later-commit subjects: Added, Fixed, Changed, Removed, Updated, …
- **Leave a blank line after the subject.** Git treats the whole first paragraph as the subject, so body lines written directly under it collapse into one long subject in `git log` and in the PR.
- Each following line: one change, same past-tense style
- One logical chunk per commit; multiple commits on the branch are fine
- Pass the message with `git commit -F <file>` when it has a body; chained `-m` flags and shell quoting mangle it.
- **Never add Cursor attribution.** Do not pass `--trailer` for `Co-authored-by: Cursor`, `Made-with: Cursor`, or similar. After every commit, run `git log -1 --format=%B` and, if a Cursor trailer was injected, amend it out when the amend rules below allow; otherwise stop and tell the human.

Example first commit (PR title is the same first line):

```text
task resolve #48 Dropped trailing PR id from titles

Removed the create-then-retitle step from ship-issue
Updated the title format, length guidance, workflow and checklist
```

Bootstrap without an issue: the first commit subject is still the PR title (short past-tense, no trailing `(#N)`).

## Local CI

GitHub still runs `.github/workflows/ci.yml` on the PR, but the agent does not wait for it. Run the same checks here, before pushing:

| CI job | Local command |
| --- | --- |
| Tests | `php artisan migrate` (test database), then `composer test` |
| Style | `composer lint` — or `vendor/bin/pint --dirty --format agent` to fix, which satisfies it |
| Static analysis | `composer analyse` |
| Frontend build (part of Tests) | `npm run build`, and `npm ci` first when `package-lock.json` changed |

Skip a job only when the branch cannot affect it — no PHP changed, so no Pint; no JS, CSS or `package.json` changed, so no build. Everything else runs.

Fix failures locally and re-run until they pass, then push. Never poll the PR's checks, never sleep waiting for them, and never make the handoff conditional on them. If GitHub later disagrees with the local run, the human says so and the fix loop starts again from a new commit.

## Push

- `git push -u origin HEAD` (or equivalent) on the issue branch only.
- Never push to `main`.

## Pull request

### Title

Whole title ≈ **60 characters or less**:

```text
<issue_type> <verb> #<issue_id> <Past description>
```

| Issue type | Verb |
| --- | --- |
| `bug` | `fix` |
| `feature` | `resolve` |
| `task` | `resolve` |
| `config` | `fix` if corrected; `resolve` if added or set up |

If over ~60 characters, **shorten the description first** — never drop type, verb, or `#<issue_id>`.

Do not append `(#<pr_id>)`. GitHub already shows the PR number in the UI and in links. This title is also the first commit's subject.

1. If a PR for this branch/issue already exists, **update that PR** (do not open a duplicate).
2. Otherwise create the PR with this title. Do not retitle it afterwards to add a number.
3. Base branch: `main`. Open as a ready PR (not draft) unless the user asks for draft.

Example: `feature resolve #9 Added agent skills`

Bootstrap without an issue: short past-tense title, still without a trailing `(#N)`.

### Body

PR body = this branch’s commit messages (subjects and body lines), in order. No long essay. No `Closes` / `Fixes` trailer — link the issue in a review comment with `#<issue_id>`.

### Hand off

When implementation is done, the PR exists, and the [local CI](#local-ci) checks pass:

1. Brief PR comment(s): what changed, how to verify, and which tests cover the change (or why the issue is exempt). Do not over-explain.
2. Comment linking `#<issue_id>` when an issue was used.
3. Stop committing. Do not merge, and do not wait for GitHub's checks to report. Do not close the issue or alter `ok` after handoff — humans handle merge and issue lifecycle.
4. Return the PR URL.

## Workflow

1. Pick / confirm issue → verify `ok` → resolve type and id (backfill priority if missing).
2. Sync `main` (`git checkout main && git pull --ff-only`), then create or resume `<type>_<id>` from it.
3. Implement on that branch only, including the tests the change requires.
4. Decide the PR title, then pre-commit checks (coverage for the change, then passing tests) → past-tense commit(s), first subject = that title.
5. Run the [local CI](#local-ci) checks; fix and re-run until they pass → push to `origin`.
6. Open or update PR with that same title; no trailing `(#<pr_id>)`.
7. Brief review comments + issue link; hand off with PR URL without waiting for GitHub's checks.

## Done checklist

- [ ] Issue has `ok` (or explicit user waiver / true bootstrap); agent did not add or remove `ok`
- [ ] Labels used are only from the catalog; no new label names created
- [ ] Not on `main`; branched from a freshly pulled `main`; branch is `<type>_<id>` when issue-based; resumed existing branch/PR if present
- [ ] First commit subject equals the PR title exactly; later commits use a short past-tense subject
- [ ] Commit subject is separated from its body by a blank line
- [ ] Commit message has no `Co-authored-by: Cursor` / `Made-with: Cursor` trailer unless the human asked for it
- [ ] No secrets staged; `.gitignore` respected
- [ ] Application-code issues include new or updated tests for the change and its important failure modes; a green unchanged suite is not enough
- [ ] Tests passed before each commit; Pint when PHP changed
- [ ] Commits are past tense (subject + one-change body lines)
- [ ] Pushed to `origin` on the issue branch only
- [ ] PR title matches convention, ~60 chars, no trailing `(#<pr_id>)`; body is commit messages
- [ ] Every applicable CI job was run locally and passed; GitHub's checks were not waited on
- [ ] Brief review comments posted; PR not merged; PR URL returned
