# VERSIONING_POLICY
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-02  
Scope: Version governance for prompts, AKP, policies, and posting rules.

## 1) Policy Goal
All AI-governed behavior MUST be versioned, traceable, and reversible.

Governed artifacts:
- prompts
- AKP packages
- policy packs
- posting rules

## 2) Version Model
Semantic model:
- `MAJOR.MINOR.PATCH`

Rules:
- `MAJOR`: breaking change (requires explicit migration window).
- `MINOR`: additive backward-compatible behavior.
- `PATCH`: fixes without contract shape changes.

## 3) Prompt Versioning
Each prompt artifact SHALL include:
- `prompt_id`
- `version`
- `owner`
- `effective_from`
- `compatible_policies`
- `rollback_to`

Prompt updates SHALL:
- preserve output contract compatibility, or
- be released as MAJOR with migration notice.

## 4) AKP Versioning
Each AKP release SHALL include:
- `akp_id`
- `version`
- `embedding_profile` (`model=gemini-embedding-001`, `dim=768`, `metric=Cosine`)
- `source_manifest`
- `quality_report`
- `rollback_to`

AKP releases SHALL be immutable once published.

## 5) Policy Pack Versioning
Policy packs define guardrails and risk controls.

Mandatory metadata:
- `policy_pack_id`
- `version`
- `ruleset_hash`
- `effective_from`
- `supersedes` (optional)
- `rollback_to`

Policy pack changes SHALL be additive by default.

## 6) Posting Rules Versioning
Posting rules (financial/accounting/event posting logic) SHALL be versioned separately.

Mandatory metadata:
- `posting_rules_id`
- `version`
- `jurisdiction`
- `effective_from`
- `compatibility_scope`
- `rollback_to`

Posting rules MUST support parallel validation against previous stable version before cutover.

## 7) Compatibility Contract
Compatibility classes:
- `backward_compatible`
- `requires_migration`
- `forbidden_release`

A release SHALL be blocked if compatibility is `forbidden_release`.

## 8) Rollback Strategy (Immediate)
Rollback trigger examples:
- quality KPI breach,
- hallucination spike,
- invalid JSON spike,
- policy violation or tenant isolation incident.

Rollback sequence:
1. Freeze new promotions.
2. Restore last stable prompt/AKP/policy/posting bundle.
3. Re-run regression dataset.
4. Re-open traffic only after pass.

## 9) Amendment-Only Rule
Full rewrites are prohibited for active governance artifacts.  
Only compatible amendments are allowed unless MAJOR migration is explicitly approved.

## 10) Release Manifest (Required)
Every release candidate SHALL publish one manifest with:
- artifact versions (prompt, AKP, policy pack, posting rules)
- compatibility class
- rollback pointer
- QA evidence references

## 11) Non-Goal
This file defines versioning policy only. It does not execute deployment or data migration.
