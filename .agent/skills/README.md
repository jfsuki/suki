# SUKI Workspace Skills Pack

This folder contains workspace-local skills for Antigravity and any other LLM workflow that needs repo-native SUKI context.

Why this exists:
- keep SUKI architecture rules in one versioned workspace path,
- reduce prompt drift across Codex, Antigravity, and future agents,
- provide concise, reusable guidance without changing runtime behavior.

How to use with Antigravity:
- point Antigravity skills/config to `C:\laragon\www\suki\.agent\skills`
- load one or more folders from this pack depending on the task
- treat the repo canons under `docs/canon/` and `framework/docs/` as the source of truth

Workspace rules:
- these skills are local to this repository and versioned with the project,
- they are reference material only; they do not execute code by themselves,
- the same files can be read by Codex or any other LLM as shared architectural context.

Included skills:
- `suki-master-alignment`
- `suki-architect-reasoning`
- `suki-runtime-laws`
- `suki-multitenant-guard`
- `suki-agent-governance`
- `suki-builder-discipline`
- `suki-canon-writer`
- `suki-codex-optimizer`
