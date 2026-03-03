# RUNTIME_ARTIFACTS_POLICY
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-02  
Scope: Hygiene policy for runtime/cache artifacts.

## 1) Objective
Prevent runtime state and cache artifacts from entering source control and contaminating releases.

## 2) Runtime/Cache Artifacts
The following are runtime/cache artifacts (non-source):
- registry/runtime sqlite files (example: `project/storage/meta/project_registry.sqlite`)
- cache snapshots (example: `project/storage/cache/*.cache.json`)
- generated runtime backups and logs in `project/storage/*`
- ephemeral test artifacts under `framework/tests/tmp/`

These files are environment state, not canonical contracts.

## 3) Storage Location Policy
- Runtime and cache artifacts SHALL live under `project/storage/` (or external storage in production).
- Runtime state SHALL NOT be stored inside source contract folders.
- For production deployments, backups and mutable state SHOULD be outside the repository working tree when infrastructure allows it.

## 4) GitIgnore Policy
- Runtime/cache artifacts MUST be covered by `.gitignore`.
- New runtime file patterns MUST be added before enabling related features.
- Ignore coverage should prefer narrow paths under `project/storage/` to avoid hiding source files.

## 5) Commit Prohibition
- Committing runtime state is forbidden.
- Committing mutable cache snapshots is forbidden.
- If a runtime artifact is already tracked, it MUST be removed from index in a controlled hygiene change (without deleting local data).

## 6) Pre-Commit Hygiene Check
Before commit:
1. Validate that no `project/storage/*` mutable state is staged.
2. Validate that no cache snapshot (`*.cache.json`) is staged.
3. Validate that DB/runtime sqlite state is not staged unless explicitly approved migration artifact.

## 7) Development Regeneration Instructions
### 7.1 `project/storage/meta/project_registry.sqlite`
- Classification: runtime registry state.
- Regeneration: automatic at first runtime use (bootstrap/tests/chat endpoints).
- Safe local flow:
  1. Ensure the file is not staged in git.
  2. Run normal local bootstrap/test flow to let runtime recreate the file.
  3. If needed, restore from backup using `framework/scripts/db_backup.php` outputs.

### 7.2 `project/storage/cache/entities.schema.cache.json`
- Classification: runtime cache snapshot.
- Regeneration: automatic on entity contract/cache warm-up during normal runtime/tests.
- Safe local flow:
  1. Delete local cache file when troubleshooting cache drift.
  2. Run normal registry/entity read flow to regenerate cache.
  3. Keep the regenerated file untracked.

## 8) Non-Goal
This policy defines repository hygiene only. It does not alter runtime logic or database behavior.
