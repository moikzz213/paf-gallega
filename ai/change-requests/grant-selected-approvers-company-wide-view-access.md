# Change Request

## Subject

Introduce a per-user "View all records" permission so that nominated approvers can see the full
invoice and payment-request register — including the dashboard and reports — while their approval
duties and their inability to change records remain exactly as they are today.

---

## Executive Summary

Two members of staff who serve as approvers, Hanish and Ashit, have reported that they cannot see
the organisation's invoices. They see only the invoices contained in a payment request that has
been formally routed to them for signature, plus anything they submitted themselves. Their
dashboard figures, their payment-request list and their reports are narrowed in the same way, so
each of those screens shows them a small slice of the business rather than the whole picture.

This is not a fault. The platform was deliberately built so that visibility follows a person's
role: Administrators and the Finance team see everything, approvers see what is on their desk, and
everyone else sees their own submissions. That rule is sound as a default, but it currently has no
exceptions — there is no way for an Administrator to grant one named individual a wider view
without also handing them a different job.

The change introduces a single, explicit permission that an Administrator can switch on for a
chosen person: **View all records**. When it is on, that person sees the complete invoice register,
the complete payment-request register, dashboard totals for the whole company, and company-wide
reports. When it is off — which is how every existing account will remain, and how every new
account will be created — nothing whatsoever changes.

Critically, this is a **read-only** grant. It confers no new ability to create, edit, post, query,
cancel or pay anything, and it does not alter who may approve what. Approval authority in this
platform is decided by a separate rule — whether a person's name sits on a given stage of a given
payment request — and that rule is untouched. A person with the wider view still sees only their
own items in their approval queue, and still cannot sign off on a payment request that was not
routed to them.

The two named individuals would be granted the permission on the day of release. No other account
is affected. The recommended risk rating is **Medium**, driven entirely by the fact that the change
widens who can see commercially sensitive vendor and payment information — not by technical
complexity, which is low.

---

## Business Reason for Change

**The operational need.** An approver who can see only the requests routed to them is approving in
isolation. They cannot check whether a vendor has other invoices in flight, whether a similar
charge has already been paid this month, whether an amount is consistent with the same vendor's
recent history, or where a request sits in the wider payment pipeline. That context is precisely
what a reviewer needs in order to approve responsibly rather than mechanically. Senior approvers
are being asked to exercise judgement while being denied the information that judgement requires.

**The current workaround is worse.** Today the only way to give an approver a company-wide view is
to move them into the Finance or Administrator role. That would work, but it would also hand them
the ability to post invoices to the ERP, raise queries, correct invoices in place, create payment
requests, mark payments as made, and edit master data. Granting a broad set of powers in order to
satisfy a narrow request for visibility is poor practice, hard to audit and hard to reverse. It is
the kind of role inflation that audit findings are made of.

**The structural gap.** Visibility is currently derived solely from the role name. Any adjustment
to it is therefore all-or-nothing: either every approver in the organisation gains the wider view,
or none does. Neither outcome matches a request about two specific senior people. The platform
needs the ability to make a deliberate, named, reversible exception — and to record who made it.

**Why now.** The request has come directly from the approvers concerned and is impeding their
day-to-day duties. There is no deadline pressure and no compliance breach, so this is a planned
improvement rather than an urgent fix.

---

## Affected Business Areas

### Departments and teams

- **Approvers** — the only group whose experience can change, and only for the individuals
  explicitly granted the permission.
- **Administration / IT** — gains a new setting to manage on the user-administration screen, and
  becomes responsible for deciding who receives it.
- **Finance and Accounts Payable** — no change to their own access; they may see approvers raising
  better-informed questions about invoices outside those approvers' own queues.
- **Requesters and general staff** — no change.

### Users

- Hanish and Ashit, on release.
- Any future individual an Administrator chooses to nominate.
- Every other account is unchanged, including all other approvers.

### Business processes

- **Invoice review** — an authorised approver can consult the whole register for context before
  signing.
- **Payment approval** — the decision itself, the routing, the sequence of stages and the approval
  queue are all unchanged.
- **Invoice submission, ERP posting, query handling and payment marking** — unchanged; these remain
  restricted to invoice owners, Finance and Administrators.

### Screens and reports

- Invoice Log — the register becomes complete for an authorised approver.
- Invoice detail and its attachments — become openable for any invoice, read-only.
- Payment Requests list and payment-request detail, including the approved-request PDF — become
  complete for an authorised approver.
- Dashboard — totals, status distribution, six-month trend and top-vendor figures become
  company-wide for that person.
- Reports and report exports — become company-wide for that person, including exported files.
- Approvals queue — deliberately **not** widened; it continues to show only what is on that
  person's own desk.
- Audit Log, User Administration, Approval Levels and Master Data — unchanged, and remain
  Administrator-only (Master Data also Finance).

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** The platform is fully operational. Invoices are being submitted, routed,
approved and paid. The affected approvers can and do approve everything routed to them. There is no
interruption to business continuity, no security exposure and no compliance breach — the current
behaviour is the documented, intended design.

### Workaround Availability

**Assessment:** Yes, a workaround exists — but it is not acceptable as a permanent measure.

**Justification:** An Administrator could move the two approvers into the Finance or Administrator
role, which would grant the wider view immediately. It would also grant substantial unrelated
powers over invoices, payments and master data. As a short-term bridge it is available; as a
standing arrangement it is a governance problem, which is the reason for requesting the properly
scoped permission instead. In the meantime, Finance can supply context to approvers on request.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delaying the change leaves two senior approvers working with less context than
they would like. That is a real inefficiency and a mild control weakness, but it carries no
financial loss, no reputational exposure and no regulatory consequence. It can comfortably wait for
the next planned release.

### Timeline Constraints

**Assessment:** No

**Justification:** There is no external deadline, audit date, contractual commitment or month-end
dependency attached to this request. The normal assessment, testing and approval process can be
followed in full.

**Emergency Change Classification:** **No**

**Reason:** A planned, low-complexity enhancement addressing a usability and control gap. No
continuity, security or compliance driver, a temporary workaround exists, and no timeline pressure
applies. It should follow the standard change route.

---

## Risk Assessment

### Risk Level

**Medium**

The technical work is small and self-contained, and the change is read-only. The rating is Medium
rather than Low for one reason: it widens access to commercially sensitive information — vendor
identities, invoice values, payment status and cost-centre allocations across every department.
Confidentiality, not stability, is the risk being managed here.

### Risks Identified

**1. Over-granting of the new permission (business / security — the principal risk)**

Once the switch exists it is easy to apply. Without a stated policy it could be handed out
routinely, and the deliberate, narrow exception intended here quietly becomes the norm. The
platform would then have re-created the very all-or-nothing outcome this change was designed to
avoid.

**2. Wider exposure of commercially sensitive information (security / confidentiality)**

An authorised approver will be able to see every vendor relationship, every invoice value and every
payment position in the organisation, and to open the supporting attachments and export
whole-company report files. For a senior approver this is appropriate. It is nonetheless a genuine
increase in the amount of confidential information reachable by that account, and it enlarges what
is exposed should the account ever be compromised.

**3. Accidental widening of approval authority (business / control — the most serious if realised)**

Viewing and approving are separate controls in this platform. If the two were confused during
implementation, an approver could gain the ability to act on payment requests never routed to them
— a direct breach of segregation of duties and of the approval matrix. The likelihood is low
because the controls are genuinely independent in the design, but the consequence would be severe,
so it must be verified explicitly rather than assumed.

**4. Accidental widening of access for all approvers (business / control)**

The rule that narrows visibility is shared across several screens. An implementation that relaxed
the rule itself rather than adding a per-person exception would silently grant every approver in
the organisation — present and future — a company-wide view.

**5. Inconsistent behaviour between screens (operational / user confidence)**

Because the same underlying rules feed the invoice register, the payment-request register, the
dashboard and the reports, a partial implementation would leave a person seeing all invoices but
only some payment requests, or a dashboard total that disagrees with the list beneath it. Figures
that do not reconcile undermine trust in the platform's numbers.

**6. Loss of an audit trail for the grant (compliance)**

If switching the permission on or off is not recorded, the organisation cannot later demonstrate
who was given company-wide sight of financial data, by whom, or when.

**7. Report and dashboard performance (operational)**

Whole-company dashboard figures and report exports are heavier to produce than a filtered slice.
This is exactly the work already performed for every Finance and Administrator user today, so the
volumes are proven, but a small number of additional accounts will now generate it.

### Risk Mitigation Plan

1. **Write the grant policy down before release.** State that the permission is reserved for senior
   approvers with a demonstrable need for company-wide oversight, that each grant is requested and
   recorded, and that it is reviewed periodically. Mitigates risks 1 and 2.
2. **Default to off, everywhere.** Every existing account and every newly created account starts
   without the permission, so the release itself changes nothing until an Administrator acts.
   Mitigates risks 1 and 4.
3. **Grant it as a named exception, not a role rule.** The permission attaches to an individual and
   is intentionally not derived from their role, so no other approver is affected now or in future.
   Mitigates risks 1 and 4.
4. **Treat "view" and "approve" as separate controls and prove it in testing.** A dedicated test
   must confirm that a person holding the permission still cannot approve, reject, withdraw or mark
   as paid any payment request not routed to them, and that their approval queue is unchanged.
   Mitigates risk 3.
5. **Apply the change to all four surfaces in one release.** The invoice register, the
   payment-request register, the dashboard and the reports must move together, and testing must
   confirm the dashboard, the lists and the exports all reconcile. Mitigates risk 5.
6. **Record every grant and revocation in the audit trail**, alongside the existing record of role
   and status changes on a user account. Mitigates risk 6.
7. **Restrict who can grant it.** Only Administrators may set the permission, on the existing
   Administrator-only user-administration screen. Mitigates risks 1 and 6.
8. **Confirm no new write powers are introduced.** Testing must show that editing, posting, raising
   a query, cancelling, deleting, creating a payment request and marking a payment as made all
   remain refused for the holder. Mitigates risks 2 and 3.
9. **Keep the grant instantly reversible.** Switching the permission off restores the previous
   behaviour immediately, with no data change and no deployment. Mitigates risks 1, 2 and 7.
10. **Observe report and dashboard response times** for the newly authorised accounts after
    release. Mitigates risk 7.

---

## Expected Business Impact

### Positive Impact

- **Better-informed approval decisions.** Approvers can see a vendor's wider position, spot
  duplicate or unusual charges, and sanity-check an amount before committing the organisation to
  pay it. This is a strengthening of financial control, not a relaxation of it.
- **No role inflation.** The organisation can satisfy a request for visibility without granting
  powers over invoices, payments or master data — keeping segregation of duties intact and the
  approval matrix meaningful.
- **A reusable, governed mechanism.** The next request of this kind becomes an administrative
  decision recorded in the audit trail, rather than another change request.
- **Fewer manual information requests.** Approvers can answer their own contextual questions
  instead of asking Finance to look things up, reducing interruption on both sides.
- **Immediate reversibility.** Access can be withdrawn by an Administrator at once, without a
  release.

### Potential Negative Impact

- **A genuine widening of confidential-data access** for the accounts granted the permission, and a
  correspondingly larger exposure if such an account were ever compromised.
- **An ongoing governance obligation.** Someone must own the decision about who holds the
  permission and review it periodically; left unattended, permissions of this kind accumulate.
- **A slightly larger administrative surface.** One more setting for Administrators to understand
  and apply correctly when creating or editing users.
- **Marginal additional load** on dashboard and report generation for the affected accounts,
  comparable to that of an existing Finance user.

### User Impact

- **Hanish and Ashit** — on release, see the complete invoice register and payment-request
  register, a company-wide dashboard, and company-wide reports. Their approval queue, their
  approval authority and their inability to change records are all exactly as before. No retraining
  is needed; the screens they already use simply show the full picture.
- **All other approvers** — no change of any kind.
- **Administrators** — see one new switch on the user form and become responsible for applying it
  in line with the agreed policy.
- **Finance, requesters and all other staff** — no change.
- **No user needs to sign in again**, and no existing screen, menu or navigation path changes.

### Reporting Impact

- No report is added, removed or restructured; no column, filter or export format changes.
- For an authorised approver, existing reports and their exported files widen from "my routed
  items" to the whole company — the same output a Finance user already receives.
- Dashboard cards, the status breakdown, the six-month trend and the top-vendor list widen in the
  same way, and must reconcile with the lists beneath them.
- Reports for every other user are unchanged.
- Exported files leave the platform, so the wider export scope should be considered part of the
  confidentiality decision when granting the permission.

### Compliance Impact

- **Segregation of duties is preserved.** Approval authority is unchanged; this grant is read-only.
  Testing must demonstrate this rather than assert it.
- **Least privilege is better served than by the alternative.** Granting a narrow read permission is
  materially more defensible than promoting a person to Finance or Administrator to achieve the same
  visibility.
- **Auditability improves**, provided grants and revocations are recorded: the organisation gains a
  documented, reviewable answer to "who could see all financial records, and since when?"
- **No change to data retention, personal-data handling or regulatory reporting.** The information
  concerned is commercial (vendors, invoices, payments), not personal.
- The permission should be added to whatever periodic access review the organisation already
  performs over roles.

---

## Implementation Overview

The work is deliberately small and contained.

A new permission — **View all records** — is added to a user's profile. It is a simple on/off
setting, off for every existing and future account unless deliberately switched on, and it can be
set only by an Administrator, on the existing user-administration screen. Setting or clearing it is
recorded in the audit trail alongside the existing record of changes to a user's role, department
and active status.

The platform's existing visibility rules are then taught to respect it. Those rules already
recognise "this person may see everything" for Administrators and Finance; the change is to have
them also recognise a person who holds the new permission. Because visibility for invoices and for
payment requests is each governed centrally, and because the dashboard, the reports and the detail
screens draw on those same central rules, the effect reaches all four agreed surfaces — invoices,
payment requests, dashboard and reports — consistently, together, from a small number of
adjustments in one place each.

Two safeguards are part of the implementation rather than afterthoughts. First, the permission is
read-only by construction: it is consulted only where the platform decides *what a person may see*,
and nowhere that decides *what a person may do*. Second, the approval queue is left alone
deliberately, so that a wider view does not become a wider mandate.

Finally, the affected knowledge-base documentation is updated so the new permission is described
alongside the roles it complements, and the two nominated approvers are granted the permission once
the release is verified in production.

No data is migrated, no existing record is altered, and no integration or external interface is
involved. The release requires the usual application deployment and refreshed front-end assets.

---

## Rollout Plan

1. **Development** — add the permission, honour it in the platform's visibility rules for invoices
   and payment requests, expose the switch on the Administrator user screen, record grants in the
   audit trail, and add automated tests covering both what the permission grants and — equally
   important — what it must not grant.
2. **Internal Validation** — a developer confirms on a test account that all four surfaces widen
   together and reconcile, that approval authority and the approval queue are unchanged, and that an
   approver without the permission sees no difference at all.
3. **QA Verification** — the full Test Case document is executed, with particular attention to the
   security and regression sections. Sign-off is withheld until the "cannot approve what is not
   routed to me" and "cannot edit anything" cases pass explicitly.
4. **User Acceptance Testing** — Hanish and Ashit each confirm on a test account that they can see
   the full register and that their approval work is unaffected. Finance confirms their own access
   is unchanged. An Administrator confirms the switch is understandable and that the audit entry
   appears.
5. **Production Deployment** — deploy the application update and refreshed front-end assets during
   an agreed window. The release is inert on arrival: no account holds the permission yet.
6. **Grant the permission** — an Administrator switches it on for the two named approvers and
   verifies the audit entries.
7. **Post Deployment Monitoring** — for the first two weeks, confirm with the two approvers that the
   wider view is working as expected, watch dashboard and report response times for their accounts,
   and spot-check that no other approver's access has changed.

---

## Backout Plan

The change is unusually easy to reverse, in stages of increasing scope.

1. **Withdraw the grant (seconds, no deployment).** An Administrator switches the permission off for
   the affected users. Their access returns immediately to exactly the previous behaviour. This
   alone resolves any concern about over-exposure and is the expected response to any issue found
   after release.
2. **Suspend the functionality.** If the permission itself is judged unsafe, ensure no account holds
   it. With nothing granted, the platform behaves precisely as it does today.
3. **Restore the previous application state.** Redeploy the prior application version and its
   front-end assets. No data conversion took place, so no data unwinding is required; the unused
   setting simply becomes dormant.
4. **Restore from backup** — not expected to be necessary, as no existing record is modified.
   Standard pre-deployment backups are taken regardless.
5. **Validate business operations** — confirm invoice submission, ERP posting, payment-request
   creation, the approval chain and payment marking all behave normally, and that Finance and
   Administrator access is intact.
6. **Notify stakeholders** — inform the two approvers, Finance and IT of the reversal and the
   reason.

---

## Approval Requirements

### Requestor

Abdul Rahman, on behalf of Hanish and Ashit, the approvers who raised the request.

### Department Manager

Required — confirming that company-wide visibility of vendor, invoice and payment information is
appropriate for the two named individuals.

### IT Manager

Required — confirming the implementation approach, the read-only nature of the grant, the audit
recording, and the deployment and backout plans.

### Business Owner (Finance / Accounts Payable)

Required — Finance owns the invoice and payment registers whose confidentiality scope is being
widened, and should confirm both the grant and the standing policy for future grants.

### CAB Approval

Recommended. The change is technically minor but alters who may see the organisation's financial
records, and establishes a permission that will be reused. CAB should note the grant policy and the
periodic review obligation.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-09-08

Risk Rating: **Medium** — low technical complexity and fully reversible, rated Medium because it
widens access to commercially sensitive financial information

Emergency Change: **No**

Analysis Confidence: **93%**

- Affected Features Confidence: 96% — the surfaces governed by the platform's visibility rules were
  identified directly from the source and are unambiguous
- Business Impact Confidence: 92% — the effect on the two named users is clear; the extent to which
  other individuals will later be nominated is a management decision, not a technical one
- Security Impact Confidence: 94% — viewing and approving are demonstrably separate controls in the
  current design; the residual uncertainty is organisational (who should hold the permission), not
  technical
- Risk Assessment Confidence: 90% — the risks are well understood; the dominant one is governance
  discipline over time, which cannot be verified from the codebase

Confidence is held below full certainty on three points that code alone cannot settle: whether the
organisation wishes to adopt a written policy governing future grants; whether an existing periodic
access review exists that the permission can be folded into; and current production data volumes,
which determine whether whole-company dashboard and report generation is noticeably slower for the
newly authorised accounts (it is already performed for every Finance and Administrator user, so this
is expected to be immaterial).

---

## Technical Analysis Appendix

*Retained for the implementation team and IT reviewers; not required reading for business
approvers.*

### Affected Systems

- User account record — one new stored setting per user, defaulting to off.
- Central visibility rules for invoices and for payment requests — each governed in a single place
  and consulted by the registers, the detail screens, the attachment access check, the dashboard and
  the reports.
- Administrator user-management screen, and the user-update validation and audit entry behind it.
- Two single-record access checks (invoice detail, invoice attachment download) that currently
  restate the visibility rule inline and must be kept in step — a known duplication that this change
  should consolidate rather than triplicate.

### Integrations

None. No external system, third-party interface or ERP integration is involved. No published
interface contract changes; the existing internal endpoints keep their shape and simply return the
wider result set for an authorised person.

### Data Storage

One additional stored setting on the user record. No existing data is read, rewritten or migrated.
No new table, and no change to any invoice, payment-request or reporting data.

### Security Controls

- **Authentication** — unchanged.
- **Authorisation** — one new, explicitly read-only permission. It is consulted only by *what may
  this person see* checks and by none of the *what may this person do* checks.
- **Segregation of duties** — preserved. Approval authority remains determined by whether the
  person's name is on the relevant stage of the relevant payment request, and the approval queue
  remains scoped to that same rule. Both must be covered by explicit automated tests.
- **Data exposure** — a real but bounded and intended widening, equivalent to the access a Finance
  user already holds, and read-only.
- **Audit** — grants and revocations recorded with the existing user-change audit entry.
- **Grant control** — Administrator-only, on an already Administrator-restricted screen.

### Architecture Impact

None structural. This follows the pattern already established for Finance users who are nominated as
approvers: a role-derived default, with a deliberate, recorded, per-person exception. No new layer,
service or dependency.

### Existing Assumption Requiring Change

The platform currently assumes visibility is a pure function of role name — expressed in the
documented rule "Administrators and Finance can see every invoice; approvers see those routed to
them." That assumption becomes "role name, unless the account carries an explicit grant." Every
place relying on the old assumption must be updated together, and the knowledge-base documentation
describing it must be corrected in the same release; leaving a stale statement behind is how the
inconsistency risk above materialises.
