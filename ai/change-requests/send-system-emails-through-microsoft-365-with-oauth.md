# Change Request

## Subject

Send System Emails Through Microsoft 365 Using Modern (OAuth) Sign-In, Switchable From Server Configuration

---

## Executive Summary

The platform sends every notification by email: payment requests awaiting approval, approvals, queries raised, rejections, reminders, password resets and the weekly query report to department heads. Today these emails go out through a mail server connection (SMTP) that signs in with a mailbox username and password.

This change lets the platform sign in to the organisation's Microsoft 365 mail service with **modern sign-in (OAuth 2.0)** instead of a password. The platform authenticates as an application registered in the organisation's Microsoft 365 tenant. Emails still travel the same way, through Microsoft 365's standard mail-sending service. Only the way the platform proves its identity changes.

The choice between the current password sign-in and the new modern sign-in is a **single setting in the server's configuration file**. Nothing in the application screens changes. Users will notice nothing, and emails keep arriving from the same sender address. Switching back is the same one-line setting, so the change can be trialled and reversed without a new release.

The expected outcome is email delivery that keeps working when Microsoft or IT policy disables password-based mail sign-in, and that no longer breaks when the mailbox password changes.

---

## Business Reason for Change

**Password-based mail sign-in is being phased out.** Microsoft is retiring basic (username and password) sign-in for sending mail through Microsoft 365, and many organisations already block it by policy. Modern sign-in is the supported replacement for the same mail service. If the platform's mailbox is affected and nothing changes, notifications stop.

**Approvals depend on email.** Approvers are notified, reminded and given their approval link by email. An email outage is therefore a payment-processing outage, not just an inconvenience.

**Password changes silently break mail today.** When the sending mailbox's password is rotated, the platform keeps trying the old one until someone notices that notifications have stopped.

**Central control.** An application registered in Microsoft 365 is visible to IT. It is granted access to one named mailbox only and can be disabled instantly. A mailbox password stored on a server offers none of that.

**Flexibility is retained.** Development, testing, and any site without Microsoft 365 still need the current method. Keeping both, selected by configuration, avoids forcing every environment onto one approach.

---

## Affected Business Areas

**Departments and teams**

- All platform users who receive notifications: requesters, approvers at every level, Finance, department heads, and administrators
- IT / Microsoft 365 administrators, who register the application, grant it access to the sending mailbox, and own its credential

**Processes**

- Approval routing and reminders (approval links are delivered by email)
- Invoice and payment-request query notifications
- Approval, rejection and payment confirmations
- Password reset
- Weekly query-raised report to department heads

**Reports**

- No report content changes.

---

## Emergency Change Assessment

### Business Continuity

Assessment: No

Justification: Email works today. The change protects against a future outage rather than fixing a current one.

### Workaround Availability

Assessment: Yes

Justification: The current password sign-in keeps working until it is blocked for the sending mailbox.

### Operational Impact

Assessment: No

Justification: Delaying the change carries no immediate cost. The risk grows only as password-based sign-in is retired.

### Timeline Constraints

Assessment: No

Justification: There is time to follow the normal assessment, approval and testing process.

Emergency Change Classification: **No**

Reason: This is a planned, preventive improvement to an existing service, with a workaround in place.

---

## Risk Assessment

### Risk Level

**Low**

The change is additive and stays off until the configuration selects it. Emails go through the same Microsoft 365 mail service, with the same recipients and wording. Only the sign-in method differs.

### Risks Identified

1. **Credential expiry stops email.** The application's credential issued by Microsoft 365 has an expiry date (at most two years). When it expires, emails stop until a new credential is configured.
2. **Microsoft 365 setup is incomplete.** Modern sign-in for mail needs several steps in Microsoft 365: registering the application, granting the mail permission, giving it access to the mailbox, and keeping mail sign-in allowed for that mailbox. If one is missed, emails are not sent.
3. **Misconfiguration at go-live.** A wrong tenant, application or mailbox value means emails are not sent.
4. **Longer-term direction.** Microsoft is pushing new integrations toward a different interface (Microsoft Graph). It supports modern sign-in for this mail service today. If that support ever ends, a further change would be needed. This is not expected in the near term.

### Risk Mitigation Plan

1. **Expiry:** IT records the credential's expiry date and schedules renewal before it. Emails that fail are not lost: they stay queued, retry automatically, and are kept for re-sending once the credential is renewed. Each failure is written to the application log.
2. **Setup:** The required Microsoft 365 steps are listed in the appendix and in the deployment guide. Each one is checked in the test environment before production.
3. **Misconfiguration:** Validated in the test environment first. Go-live is a configuration change that can be reversed in minutes, and any emails that failed in the meantime can be re-sent.
4. **Direction:** The delivery layer is isolated, so a later move to another Microsoft interface would be a similar, self-contained change.

---

## Expected Business Impact

### Positive Impact

- Notifications keep working when password-based mail sign-in is disabled
- No outage when a mailbox password is changed
- The application can reach only the one mailbox it is granted, and IT can revoke it centrally
- The current method remains available for environments that need it

### Potential Negative Impact

- A new recurring IT task: renewing the application credential before it expires
- Initial setup effort in Microsoft 365

### User Impact

None visible. Users receive the same emails from the same sender address.

### Reporting Impact

None.

### Compliance Impact

Positive. A stored mailbox password is replaced by a centrally governed, revocable application identity that is limited to one mailbox (least privilege). The credential is held only in the server's protected configuration file and is never stored in the code repository.

---

## Implementation Overview

The platform will gain a "Microsoft 365 (modern sign-in)" email option alongside the current one. When it is selected, the platform obtains a short-lived access pass from the organisation's Microsoft 365 tenant using the application's identity. It signs in to Microsoft 365's mail service with that pass and sends the email from the configured sender mailbox. A new pass is obtained automatically shortly before the previous one expires.

Which option is in use, and the tenant connection details, are set only in the server's configuration file. Nothing is configured through the application screens, and no data is stored in the database. Recipients, blind-copy handling and wording are identical under both options.

No additional software packages are required. The platform's existing mail component already supports this sign-in method.

---

## Rollout Plan

1. **Development** — Add the modern sign-in email option and its configuration settings. Add automated tests covering obtaining and renewing the access pass, sign-in, and failure handling.
2. **Internal Validation** — Send every notification type through a test tenant application and confirm delivery, sender, recipients and formatting.
3. **QA Verification** — IT completes the Microsoft 365 setup for the production mailbox. In the test environment, confirm emails send, and that a wrong credential produces queued, retryable failures rather than lost emails.
4. **User Acceptance Testing** — Business users confirm that approval, query and password-reset emails arrive as before, including approval links.
5. **Production Deployment** — Deploy the code with the current method still selected (no change in behaviour). Then switch the configuration setting to the new option and restart the background email worker.
6. **Post Deployment Monitoring** — For the first week, watch the application log and the failed-email queue daily, and confirm the daily reminders and weekly report are delivered. Record the credential expiry date with IT.

---

## Backout Plan

1. **Suspend new functionality** — Change the configuration setting back to the current mail method and restart the background email worker. No release is needed.
2. **Restore previous application state** — Not normally needed, because the current method is unchanged. If required, redeploy the previous release.
3. **Restore backups if required** — Not applicable. The change touches no data.
4. **Validate business operations** — Re-send any emails that failed while the new option was active, and confirm new notifications are delivered.
5. **Notify stakeholders** — Inform IT and the business owner of the rollback and its cause.

---

## Approval Requirements

### Requestor

### Department Manager

### IT Manager

Required: IT also owns the Microsoft 365 application registration, the mailbox access grant, and credential renewal.

### Business Owner

### CAB Approval (if applicable)

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-11-18

Risk Rating: Low

Emergency Change: No

Analysis Confidence: 90%

| Measure | Score | Note |
|---|---|---|
| Analysis Confidence | **90%** | Every email goes through one central mail layer, so the new option covers all of them. |
| Affected Features Confidence | **95%** | The platform has eleven email types. The sending service and message format are unchanged, so blind copy keeps working as it does today. |
| Business Impact Confidence | **90%** | No visible change for users. Impact is confined to delivery reliability. |
| Security Impact Confidence | **90%** | Mailbox access is granted per mailbox, so the application cannot send as other users. |
| Risk Assessment Confidence | **88%** | The main residual risks are operational: completing the Microsoft 365 setup and renewing the credential on time. |

---

# Technical Analysis Appendix

**Affected systems**

- Email delivery layer only. The existing `smtp` mailer gains a sign-in switch, `MAIL_SMTP_AUTH`: `password` (today's behaviour, the default) or `oauth`. This is the same convention used in the organisation's other projects.
- It sends over SMTP to `smtp.office365.com:587` (STARTTLS) and authenticates with `XOAUTH2`. The OAuth token comes from the client-credentials flow (`https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, scope `https://outlook.office365.com/.default`). The token is cached and refreshed before it expires, which matters because the queue worker runs for a long time.
- Not Microsoft Graph. No new composer dependencies: it uses Symfony Mailer's built-in `XOAuth2Authenticator` and Laravel's HTTP client.
- No database, API, screen or permission changes.

**Configuration (server `.env` only)**

| Setting | Purpose |
|---|---|
| `MAIL_SMTP_AUTH` | `oauth` for Microsoft sign-in. `password` uses `MAIL_USERNAME` + `MAIL_PASSWORD` as today. |
| `MAIL_HOST` / `MAIL_PORT` | `smtp.office365.com` / `587` when using `oauth` |
| `MICROSOFT_TENANT_ID` | Microsoft 365 tenant (directory) ID |
| `MICROSOFT_CLIENT_ID` | Application (client) ID of the registered app |
| `MICROSOFT_CLIENT_SECRET` | Application credential (secret), with an expiry date |
| `MAIL_USERNAME` | The mailbox the application was granted, used as the SMTP sign-in identity (falls back to `MAIL_FROM_ADDRESS`) |
| `MAIL_FROM_ADDRESS` | Sender address |

**Microsoft 365 setup (IT)**

1. Register an application in Entra ID. Add the **Office 365 Exchange Online → `SMTP.SendAsApp`** *application* permission, grant admin consent, and create a client secret.
2. In Exchange Online PowerShell, register the app's service principal (`New-ServicePrincipal`). Grant it access to the sender mailbox only (`Add-MailboxPermission … -AccessRights FullAccess`).
3. Keep SMTP AUTH enabled for that mailbox (`Set-CASMailbox <mailbox> -SmtpClientAuthenticationDisabled $false`).

**Integration behaviour**

- Emails stay on the existing background queue, so failures retry and are kept for re-sending as they are today.
