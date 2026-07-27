# API Documentation — CampaignsHub

Base URL: `/(api/v1)` · Auth: Sanctum SPA cookie (send `Origin`/`Referer` of the SPA; prime `/sanctum/csrf-cookie`). 207 endpoints.

## ai

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/ai/credentials` | api.v1.ai.credentials.index |
| POST | `/api/v1/ai/credentials` | api.v1.ai.credentials.store |
| GET | `/api/v1/ai/credentials/{credential}/health` | api.v1.ai.credentials.health |

## alerts

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/alerts/events` | api.v1.alerts.events.index |
| POST | `/api/v1/alerts/events/{alertEvent}/resolve` | api.v1.alerts.events.resolve |
| POST | `/api/v1/alerts/events/{alertEvent}/snooze` | api.v1.alerts.events.snooze |
| GET | `/api/v1/alerts/rules` | api.v1.alerts.rules.index |
| POST | `/api/v1/alerts/rules` | api.v1.alerts.rules.store |

## app

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/app/clients` | api.v1.app.clients.index |
| GET | `/api/v1/app/clients/{client}` | api.v1.app.clients.show |
| GET | `/api/v1/app/clients/{client}/activity` | api.v1.app.clients.activity |
| GET | `/api/v1/app/clients/{client}/analytics` | api.v1.app.clients.analytics |
| POST | `/api/v1/app/clients/{client}/archive` | api.v1.app.clients.archive |
| PATCH | `/api/v1/app/clients/{client}/classification` | api.v1.app.clients.classification |
| GET | `/api/v1/app/clients/{client}/files` | api.v1.app.clients.files.index |
| GET | `/api/v1/app/clients/{client}/files/{source}/{id}/download` | api.v1.app.clients.files.download |
| GET | `/api/v1/app/clients/{client}/reports` | api.v1.app.clients.reports.index |
| POST | `/api/v1/app/clients/{client}/reports` | api.v1.app.clients.reports.store |
| POST | `/api/v1/app/clients/{client}/reports/{report}/share` | api.v1.app.clients.reports.share |
| POST | `/api/v1/app/clients/{client}/reports/{report}/shares/{share}/revoke` | api.v1.app.clients.reports.revoke |
| POST | `/api/v1/app/clients/{client}/restore` | api.v1.app.clients.restore |
| PATCH | `/api/v1/app/clients/{client}/settings` | api.v1.app.clients.settings |
| GET | `/api/v1/app/clients/{client}/team` | api.v1.app.clients.team.index |
| POST | `/api/v1/app/clients/{client}/team` | api.v1.app.clients.team.store |
| PATCH | `/api/v1/app/clients/{client}/team/{user}` | api.v1.app.clients.team.update |
| DELETE | `/api/v1/app/clients/{client}/team/{user}` | api.v1.app.clients.team.destroy |
| GET | `/api/v1/app/clients/{client}/team/assignable` | api.v1.app.clients.team.assignable |
| GET | `/api/v1/app/clients/meta/taxonomy` | api.v1.app.clients.taxonomy |
| GET | `/api/v1/app/requests` | api.v1.app.requests.index |
| GET | `/api/v1/app/requests/{id}` | api.v1.app.requests.show |
| PATCH | `/api/v1/app/requests/{id}/archive` | api.v1.app.requests.archive |
| PATCH | `/api/v1/app/requests/{id}/assign` | api.v1.app.requests.assign |
| POST | `/api/v1/app/requests/{id}/convert` | api.v1.app.requests.convert |
| POST | `/api/v1/app/requests/{id}/internal-note` | api.v1.app.requests.internal-note |
| PATCH | `/api/v1/app/requests/{id}/priority` | api.v1.app.requests.priority |
| POST | `/api/v1/app/requests/{id}/reply` | api.v1.app.requests.reply |
| POST | `/api/v1/app/requests/{id}/request-information` | api.v1.app.requests.request-information |
| PATCH | `/api/v1/app/requests/{id}/status` | api.v1.app.requests.status |
| GET | `/api/v1/app/team/invitations` | api.v1.app.team.invitations.index |
| POST | `/api/v1/app/team/invitations` | api.v1.app.team.invitations.store |

## auth

| Method | Path | Name |
|---|---|---|
| POST | `/api/v1/auth/email/resend` | api.v1.auth.email.resend |
| POST | `/api/v1/auth/email/verify` | api.v1.auth.email.verify |
| POST | `/api/v1/auth/forgot-password` | api.v1.auth.forgot-password |
| POST | `/api/v1/auth/login` | api.v1.auth.login |
| POST | `/api/v1/auth/logout` | api.v1.auth.logout |
| GET | `/api/v1/auth/me` | api.v1.auth.me |
| POST | `/api/v1/auth/register` | api.v1.auth.register |
| POST | `/api/v1/auth/tokens` | api.v1.auth.tokens |

## brand

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/brand` | api.v1.brand |

## client

| Method | Path | Name |
|---|---|---|
| POST | `/api/v1/client/login/start` | api.v1.client.login.start |
| POST | `/api/v1/client/login/verify` | api.v1.client.login.verify |
| POST | `/api/v1/client/logout` | api.v1.client.logout |
| GET | `/api/v1/client/requests` | api.v1.client.requests.index |
| GET | `/api/v1/client/requests/{reference}` | api.v1.client.requests.show |
| GET | `/api/v1/client/requests/{reference}/files/{file}` | api.v1.client.requests.file |
| POST | `/api/v1/client/requests/{reference}/reply` | api.v1.client.requests.reply |
| GET | `/api/v1/client/session` | api.v1.client.session |

## client-workspaces

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/client-workspaces` | api.v1.client-workspaces.index |
| POST | `/api/v1/client-workspaces` | api.v1.client-workspaces.store |
| GET | `/api/v1/client-workspaces/{clientWorkspace}` | api.v1.client-workspaces.show |
| PUT,PATCH | `/api/v1/client-workspaces/{clientWorkspace}` | api.v1.client-workspaces.update |
| DELETE | `/api/v1/client-workspaces/{clientWorkspace}` | api.v1.client-workspaces.archive |
| POST | `/api/v1/client-workspaces/{clientWorkspace}/restore` | api.v1.client-workspaces.restore |

## connections

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/connections` | api.v1.connections.index |
| POST | `/api/v1/connections/{connection}/revoke` | api.v1.connections.revoke |

## health

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/health` | api.v1.health |

## integrations

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/integrations` | api.v1.integrations.index |
| POST | `/api/v1/integrations/{key}/connect` | api.v1.integrations.connect |
| GET | `/api/v1/integrations/{key}/health` | api.v1.integrations.health |
| POST | `/api/v1/integrations/{key}/sync` | api.v1.integrations.sync |

## invitations

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/invitations/{token}` | api.v1.invitations.preview |
| POST | `/api/v1/invitations/accept` | api.v1.invitations.accept |

## leads

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/leads` | api.v1.leads.index |
| POST | `/api/v1/leads` | api.v1.leads.store |
| GET | `/api/v1/leads/{lead}` | api.v1.leads.show |
| PUT,PATCH | `/api/v1/leads/{lead}` | api.v1.leads.update |
| DELETE | `/api/v1/leads/{lead}` | api.v1.leads.destroy |
| POST | `/api/v1/leads/{lead}/convert` | api.v1.leads.convert |

## me

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/me` | api.v1.me.show |
| PATCH | `/api/v1/me/password` | api.v1.me.password.update |
| PATCH | `/api/v1/me/profile` | api.v1.me.profile.update |
| GET | `/api/v1/me/sessions` | api.v1.me.sessions |
| DELETE | `/api/v1/me/sessions/others` | api.v1.me.sessions.others |

## notifications

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/notifications` | api.v1.notifications.index |
| POST | `/api/v1/notifications/{notification}/read` | api.v1.notifications.read |
| GET | `/api/v1/notifications/deliveries` | api.v1.notifications.deliveries |
| POST | `/api/v1/notifications/read-all` | api.v1.notifications.read-all |

## onboarding

| Method | Path | Name |
|---|---|---|
| POST | `/api/v1/onboarding/account-type` | api.v1.onboarding.account-type |
| POST | `/api/v1/onboarding/complete` | api.v1.onboarding.complete |
| POST | `/api/v1/onboarding/first-client` | api.v1.onboarding.first-client |
| POST | `/api/v1/onboarding/first-project` | api.v1.onboarding.first-project |
| POST | `/api/v1/onboarding/service` | api.v1.onboarding.service |
| GET | `/api/v1/onboarding/state` | api.v1.onboarding.state |
| POST | `/api/v1/onboarding/workspace` | api.v1.onboarding.workspace |

## opportunities

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/opportunities` | api.v1.opportunities.index |
| GET | `/api/v1/opportunities/{opportunity}` | api.v1.opportunities.show |
| POST | `/api/v1/opportunities/{opportunity}/stage` | api.v1.opportunities.stage |

## projects

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/projects` | api.v1.projects.index |
| POST | `/api/v1/projects` | api.v1.projects.store |
| GET | `/api/v1/projects/{project}` | api.v1.projects.show |
| PUT,PATCH | `/api/v1/projects/{project}` | api.v1.projects.update |
| POST | `/api/v1/projects/{project}/archive` | api.v1.projects.archive |
| GET | `/api/v1/projects/{project}/campaigns` | api.v1.projects.campaigns.index |
| POST | `/api/v1/projects/{project}/campaigns` | api.v1.projects.campaigns.store |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}` | api.v1.projects.campaigns.show |
| PUT,PATCH | `/api/v1/projects/{project}/campaigns/{campaign}` | api.v1.projects.campaigns.update |
| DELETE | `/api/v1/projects/{project}/campaigns/{campaign}` | api.v1.projects.campaigns.destroy |
| POST | `/api/v1/projects/{project}/campaigns/{campaign}/activate` | api.v1.projects.campaigns.activate |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/activity` | api.v1.projects.campaigns.activity |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/alerts` | api.v1.projects.campaigns.alerts |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/annotations` | api.v1.projects.campaigns.annotations.index |
| POST | `/api/v1/projects/{project}/campaigns/{campaign}/annotations` | api.v1.projects.campaigns.annotations.store |
| PUT,PATCH | `/api/v1/projects/{project}/campaigns/{campaign}/annotations/{annotation}` | api.v1.projects.campaigns.annotations.update |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/budget` | api.v1.projects.campaigns.budget |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/creatives` | api.v1.projects.campaigns.creatives |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/external` | api.v1.projects.campaigns.external.index |
| POST | `/api/v1/projects/{project}/campaigns/{campaign}/external` | api.v1.projects.campaigns.external.link |
| DELETE | `/api/v1/projects/{project}/campaigns/{campaign}/external/{external}` | api.v1.projects.campaigns.external.unlink |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/funnel` | api.v1.projects.campaigns.funnel |
| POST | `/api/v1/projects/{project}/campaigns/{campaign}/pause` | api.v1.projects.campaigns.pause |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/performance` | api.v1.projects.campaigns.performance |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/platforms` | api.v1.projects.campaigns.platforms |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/reports` | api.v1.projects.campaigns.reports |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/suggestions` | api.v1.projects.campaigns.suggestions |
| GET | `/api/v1/projects/{project}/campaigns/{campaign}/summary` | api.v1.projects.campaigns.summary |
| POST | `/api/v1/projects/{project}/clone` | api.v1.projects.clone |
| GET | `/api/v1/projects/{project}/disclaimer` | api.v1.projects.scoped.disclaimer.resolve |
| GET | `/api/v1/projects/{project}/external-campaigns` | api.v1.projects.campaigns.external-campaigns.index |
| GET | `/api/v1/projects/{project}/integrations` | api.v1.projects.integrations.index |
| POST | `/api/v1/projects/{project}/integrations/bindings` | api.v1.projects.integrations.bind |
| DELETE | `/api/v1/projects/{project}/integrations/bindings/{binding}` | api.v1.projects.integrations.detach |
| POST | `/api/v1/projects/{project}/integrations/bindings/{binding}/sync` | api.v1.projects.integrations.sync |
| POST | `/api/v1/projects/{project}/integrations/connect` | api.v1.projects.integrations.connect |
| GET | `/api/v1/projects/{project}/metrics/budget` | api.v1.projects.scoped.metrics.budget |
| GET | `/api/v1/projects/{project}/metrics/campaigns` | api.v1.projects.scoped.metrics.campaigns |
| GET | `/api/v1/projects/{project}/metrics/freshness` | api.v1.projects.scoped.metrics.freshness |
| GET | `/api/v1/projects/{project}/metrics/funnel` | api.v1.projects.scoped.metrics.funnel |
| GET | `/api/v1/projects/{project}/metrics/platforms` | api.v1.projects.scoped.metrics.platforms |
| GET | `/api/v1/projects/{project}/metrics/summary` | api.v1.projects.scoped.metrics.summary |
| GET | `/api/v1/projects/{project}/metrics/timeseries` | api.v1.projects.scoped.metrics.timeseries |
| GET | `/api/v1/projects/{project}/notifications` | api.v1.projects.scoped.notifications.index |
| GET | `/api/v1/projects/{project}/overview` | api.v1.projects.scoped.overview |
| POST | `/api/v1/projects/{project}/pause` | api.v1.projects.pause |
| GET | `/api/v1/projects/{project}/reports` | api.v1.projects.scoped.reports.index |
| POST | `/api/v1/projects/{project}/reports` | api.v1.projects.scoped.reports.store |
| GET | `/api/v1/projects/{project}/reports/{report}` | api.v1.projects.scoped.reports.show |
| PUT,PATCH | `/api/v1/projects/{project}/reports/{report}` | api.v1.projects.scoped.reports.update |
| DELETE | `/api/v1/projects/{project}/reports/{report}` | api.v1.projects.scoped.reports.destroy |
| GET | `/api/v1/projects/{project}/reports/{report}/annotations` | api.v1.projects.scoped.reports.annotations.index |
| POST | `/api/v1/projects/{project}/reports/{report}/annotations/{annotation}/status` | api.v1.projects.scoped.reports.annotations.status |
| POST | `/api/v1/projects/{project}/reports/{report}/export` | api.v1.projects.scoped.reports.export |
| POST | `/api/v1/projects/{project}/reports/{report}/print-token` | api.v1.projects.scoped.reports.print-token |
| POST | `/api/v1/projects/{project}/reports/{report}/regenerate` | api.v1.projects.scoped.reports.regenerate |
| POST | `/api/v1/projects/{project}/reports/{report}/send` | api.v1.projects.scoped.reports.send |
| GET | `/api/v1/projects/{project}/reports/{report}/shares` | api.v1.projects.scoped.reports.shares.index |
| POST | `/api/v1/projects/{project}/reports/{report}/shares` | api.v1.projects.scoped.reports.shares.store |
| GET | `/api/v1/projects/{project}/reports/{report}/shares/{share}/logs` | api.v1.projects.scoped.reports.shares.logs |
| POST | `/api/v1/projects/{project}/reports/{report}/shares/{share}/revoke` | api.v1.projects.scoped.reports.shares.revoke |
| GET | `/api/v1/projects/{project}/reports/{report}/validation` | api.v1.projects.scoped.reports.validation |
| GET | `/api/v1/projects/{project}/reports/template` | api.v1.projects.scoped.reports.template |
| POST | `/api/v1/projects/{project}/restore` | api.v1.projects.restore |
| POST | `/api/v1/projects/{project}/resume` | api.v1.projects.resume |
| GET | `/api/v1/projects/{project}/tasks` | api.v1.projects.scoped.tasks.index |
| GET | `/api/v1/projects/{project}/team` | api.v1.projects.scoped.team.index |
| POST | `/api/v1/projects/{project}/team` | api.v1.projects.scoped.team.store |
| PUT,PATCH | `/api/v1/projects/{project}/team/{membership}` | api.v1.projects.scoped.team.update |
| DELETE | `/api/v1/projects/{project}/team/{membership}` | api.v1.projects.scoped.team.destroy |

## ready

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/ready` | api.v1.ready |

## reports

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/reports/download/{token}` | api.v1.reports.download |
| GET | `/api/v1/reports/print/{token}` | api.v1.reports.print.data |
| GET | `/api/v1/reports/shared/{token}` | api.v1.reports.shared.show |
| GET | `/api/v1/reports/shared/{token}/download/{format}` | api.v1.reports.shared.download |

## requests

| Method | Path | Name |
|---|---|---|
| POST | `/api/v1/requests` | api.v1.requests.store |
| GET | `/api/v1/requests/meta` | api.v1.requests.meta |
| GET | `/api/v1/requests/track/{token}` | api.v1.requests.track |
| GET | `/api/v1/requests/track/{token}/files/{file}` | api.v1.requests.track.file |
| POST | `/api/v1/requests/track/{token}/reply` | api.v1.requests.track.reply |
| POST | `/api/v1/requests/uploads` | api.v1.requests.uploads.store |
| DELETE | `/api/v1/requests/uploads/{file}` | api.v1.requests.uploads.destroy |
| POST | `/api/v1/requests/uploads/start` | api.v1.requests.uploads.start |
| POST | `/api/v1/requests/verify/check` | api.v1.requests.verify.check |
| POST | `/api/v1/requests/verify/start` | api.v1.requests.verify.start |

## settings

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/settings/branding` | api.v1.settings.branding.show |
| PUT,PATCH | `/api/v1/settings/branding` | api.v1.settings.branding.update |
| GET | `/api/v1/settings/disclaimers` | api.v1.settings.disclaimers.index |
| PUT | `/api/v1/settings/disclaimers` | api.v1.settings.disclaimers.update |
| DELETE | `/api/v1/settings/disclaimers/{scope}/{scopeId?}` | api.v1.settings.disclaimers.destroy |
| GET | `/api/v1/settings/notifications` | api.v1.settings.notifications.show |
| PUT,PATCH | `/api/v1/settings/notifications` | api.v1.settings.notifications.update |
| GET | `/api/v1/settings/organization` | api.v1.settings.organization.show |
| PUT,PATCH | `/api/v1/settings/organization` | api.v1.settings.organization.update |
| GET | `/api/v1/settings/security/activity` | api.v1.settings.security.activity |
| POST | `/api/v1/settings/security/mfa/confirm` | api.v1.settings.security.mfa.confirm |
| POST | `/api/v1/settings/security/mfa/disable` | api.v1.settings.security.mfa.disable |
| POST | `/api/v1/settings/security/mfa/setup` | api.v1.settings.security.mfa.setup |
| POST | `/api/v1/settings/security/password` | api.v1.settings.security.password |
| GET | `/api/v1/settings/security/policy` | api.v1.settings.security.policy.show |
| PUT,PATCH | `/api/v1/settings/security/policy` | api.v1.settings.security.policy.update |
| GET | `/api/v1/settings/team` | api.v1.settings.team.index |
| POST | `/api/v1/settings/team` | api.v1.settings.team.invite |
| DELETE | `/api/v1/settings/team/{user}` | api.v1.settings.team.destroy |
| PUT | `/api/v1/settings/team/{user}/role` | api.v1.settings.team.role |
| POST | `/api/v1/settings/team/{user}/toggle` | api.v1.settings.team.toggle |

## tasks

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/tasks` | api.v1.tasks.index |
| POST | `/api/v1/tasks` | api.v1.tasks.store |
| PUT,PATCH | `/api/v1/tasks/{task}` | api.v1.tasks.update |

## users

| Method | Path | Name |
|---|---|---|
| GET | `/api/v1/users` | api.v1.users.index |

