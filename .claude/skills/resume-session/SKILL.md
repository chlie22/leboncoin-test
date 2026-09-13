---
name: resume-session
description: Resumes work on this repository at the start of a session - rereads docs/progress.md, checks that the workspace matches it, reports where the project stands, then starts the next plan step with the feature-workflow skill. Invoked manually by the developer with /resume-session.
disable-model-invocation: true
argument-hint: "[plan step number, defaults to the next step in docs/progress.md]"
---

# Resume session

`docs/progress.md` is the single source of progress. `CLAUDE.md` already imports it, but reread it from disk: it may have changed since the session started.

Copy this checklist and tick it as you go:

```
Resume:
- [ ] 1. Tracker reread
- [ ] 2. Workspace checked against the tracker
- [ ] 3. Status reported
- [ ] 4. Next step confirmed with the developer
- [ ] 5. Step started with feature-workflow
```

## 2. Check the workspace against the tracker

Read-only checks only:

- `git status --short` and `git log --oneline -5`, unless the directory is not a git repository yet (expected before step 1 is done).
- For every step marked done: do the files it should have created exist (`docs/conception.md` §10 and §12)? Rerun the proofs that are cheap and available, such as `npx --yes @redocly/cli@2.52.1 lint`, and `make lint` or `make test` once the Makefile exists.
- For a step marked in progress: what is already there, and what is missing against its done criterion?

Report any mismatch first: a step marked done whose proof fails, files belonging to a step not marked started, uncommitted changes. Never fix the tracker or the code silently; ask the developer.

## 3. Report

At most 10 lines:

- current phase;
- last step done, with its proof;
- step in progress, if any;
- blockers;
- next step, its done criterion, and the pitfalls listed under "Prochaine action".

## 4 and 5. Start the next step

Target: $ARGUMENTS if given, otherwise "Prochaine étape" in the tracker.

Confirm the target with the developer, mark the step 🚧 in `docs/progress.md`, then follow the `feature-workflow` skill.

## Before the session ends

Even when the step is not finished, update `docs/progress.md`:

- the step status. A step is ✅ only with its proof: the command and its exit code, or the commit hash.
- "Où on en est" and "Prochaine action", including the pitfalls discovered for what comes next;
- one line in "Décisions et écarts" per decision taken, with the updated spec section;
- one journal line for the session, keeping only the 5 latest entries;
- the file stays under 80 lines.
