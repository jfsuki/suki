# Research Benchmarks (2026-02-19)

Goal: keep the builder/chat logic aligned with current product patterns used by modern app builders.

## References reviewed
- Emergent help center: `https://help.emergent.sh/`
- Insforge (AI app/site builder patterns): `https://insforge.dev/`
- Insforge feature overview (AI website/app generation): `https://www.aibase.com/tool/24454`
- Microsoft Power Apps (create app from data): `https://learn.microsoft.com/power-apps/`
- Microsoft Learn (Dataverse canvas app): `https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/data-platform-create-app-scratch`
- Google AppSheet (data-first app creation): `https://support.google.com/appsheet/`
- AppSheet Spec (natural-language app creation): `https://support.google.com/appsheet/answer/12007354?hl=en`
- AppSheet roadmap: `https://support.google.com/appsheet/answer/12004749?hl=en`
- Supabase (multi-tenant and RLS patterns): `https://supabase.com/docs/guides/database/postgres/row-level-security`
- Supabase RBAC claims: `https://supabase.com/docs/guides/database/postgres/custom-claims-and-role-based-access-control-rbac`
- Rasa assistant design (slot filling + dialogue): `https://rasa.com/docs/`

## Patterns adopted in this framework
- Data-first flow: first entities/tables, then forms, then process/report.
- One-question policy: ask one missing critical field at a time.
- Confirm-before-create: builder proposes, user confirms (`si/no`), then create.
- Real-registry answers: chat only offers actions that exist in current app contracts.
- Dependency guardrails: invoice/work-order/lot flows enforce prerequisite entities.
- Tenant-first memory: per-user state + shared tenant research queue.
- Prompt-to-product onboarding: from Insforge/Emergent style, start with business goal and then guide the first concrete object.

## Gaps still open
- Full visual drag-drop canvas parity (builder UX still incremental).
- More explicit role-driven suggestions (admin, seller, accountant) in builder flow.
- Country-specific tax packs beyond baseline CO profile.

## Action rule
Before major chat/build logic changes:
1) Validate against these references.
2) Write logical flow first.
3) Implement incrementally.
4) Run acid + unit tests.
