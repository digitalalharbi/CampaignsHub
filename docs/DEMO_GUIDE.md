# Demo Guide

How to run a live walkthrough of what's built. Accounts/data: `docs/DEMO_ACCOUNTS.md`.

## Start
```bash
cd backend && php artisan migrate:fresh --seed && php artisan serve      # :8000
cd frontend && npm run dev                                               # :5173
```

## Journeys (built surfaces)
- **Visitor**: open `/welcome` → Features → Pricing → "Get started" → `/login`.
- **Agency**: sign in `owner@demo-agency.local` / `password` → dashboard (health/ready) → Leads
  (create + convert) → Integrations (Sandbox connect/sync; platforms show Awaiting Credentials) →
  Design system (`/design`).
- **CRM**: create a lead, then Convert → it becomes a company + contact + opportunity.
- **Content protection**: wrap any sensitive view in `<Watermark>` to see the viewer-stamped overlay
  (deterrence + attribution, not screenshot prevention).

## Planned journeys (not yet wired end-to-end in UI)
Full `/demo-tour` for Agency→Create Client Workspace→Invite→Project→Connect→Assign; Client→Accept→
Connect OpenAI key→Approve content→View report; Account Manager tasks/alerts; Media buyer pre-launch
+ sandbox launch. Backend data models for workspaces/projects/AI/tasks/notifications exist and are
tested; their portal UIs are the next build. See `KNOWN_LIMITATIONS.md`.
