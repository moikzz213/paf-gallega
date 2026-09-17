# ADR-001: Project Initialization — AI Knowledge Base & LIFT Workflow

- **Status:** Accepted
- **Date:** 2026-07-14
- **Deciders:** Project team (initialized via LIFT onboarding)

## Context

The PAF (Payment Approval Form) platform — a Laravel 13 + Vue 3 invoice payment-approval system —
existed as a working prototype with source code and a hand-written `docs/PAF-Documentation.html`,
but no machine- or agent-friendly knowledge base and no documented conventions for AI-assisted
development.

To make future development (much of it AI-assisted) reliable, consistent, and safe, the project
was analyzed end-to-end and an AI documentation layer was introduced **without modifying any
application source code**.

## Decision

The project adopts:

1. **`/ai` directory** as the project memory — structured docs an agent (or human) reads before
   working:
   - `project-context.md`, `architecture.md`, `database-schema.md`, `api-contracts.md`,
     `coding-standards.md`, `deployment.md`
   - `features/feature-overview.md`
   - `issues/` — `known-issues.md`, `technical-debt.md`, `bugs-fixed.md`, `troubleshooting.md`
   - `decisions/` — Architecture Decision Records (this file is the first)
2. **`AGENTS.md`** at the repo root — AI behavior rules, coding standards pointers, and the
   documentation/issue/decision update rules.
3. **The LIFT workflow** for all future development tasks:
   `Learn → Intend → Forge → Tune`.

## Consequences

**Benefits**
- Faster, more accurate onboarding for humans and AI agents.
- Consistent development workflow and conventions.
- A durable record of architecture decisions, known issues, and debt.

**Limitations / obligations**
- The `/ai` docs and `AGENTS.md` must be **maintained as the system evolves** — stale docs are
  worse than none. The update rules in `AGENTS.md` make this part of the definition of done.
- Documentation reflects the codebase at initialization (2026-07-14); verify specifics against
  code before relying on them for critical changes.

## Notes

- The pre-existing `docs/PAF-Documentation.html` remains as human-facing reference; the `/ai`
  docs are the agent-facing, maintained source going forward and cross-reference it where useful.
- An open product question was surfaced (demo's fixed 8-stage chain vs. the implemented
  configurable threshold chain) — tracked in `issues/`, to be resolved by a future ADR.
