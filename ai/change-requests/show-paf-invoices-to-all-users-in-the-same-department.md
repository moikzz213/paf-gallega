# Change Request

## Subject

Department-based visibility of PAF invoices. Every requester can view the invoices raised within
their own department, and cannot view invoices raised in any other department.

---

## Executive Summary

Today, a regular user of the PAF platform can see only the invoices they personally submitted. If a
colleague in the same department is on leave, has moved role, or is simply the person who happened
to key the invoice, nobody else in the team can see its status, documents or payment progress.
Follow-up then depends on one individual, or on Finance looking it up for them.

This change makes the **department** the unit of visibility. When a member of a department (for
example, Customs Clearance) raises an invoice, every user who belongs to that department can view
it, together with its attachments, its history and the payment request (PAF) it is paid through.
Users in other departments continue not to see it. **This applies to the Requester role only**;
approvers, Finance and Administrators keep their existing access. As approved, the department that counts is the
submitter's own, not the "Submitting department" picked on the invoice (see the Approval Outcome). Finance and Administrators keep their existing view of
everything.

The change is **view-only**. Seeing a colleague's invoice does not give the right to edit, cancel,
delete, correct or add documents to it. Those rights stay with the person who raised it, Finance
and Administrators. Approval authority is also unchanged: a person can approve only the stages
routed to them.

This is a significant widening of access. Around 80 active users gain sight of their department's
invoices at once, rather than only their own. The recommended risk rating is **Medium**. The access
is read-only, confined to the user's own department, and exactly what the business has requested.
It is not rated Low because it changes, for every regular user at once, the rule that decides who
can see which financial record.

Four points need a decision from approvers before implementation. They concern how a department is
determined for an invoice and what happens at the edges. They are listed under
**Decisions Requested from Approvers**, each with a recommendation.

---

## Business Reason for Change

**Business challenge.** Departments work on vendor invoices as a team, but the platform treats each
invoice as private to the person who keyed it. That creates several problems:

- **Single points of failure.** When the submitter is absent, colleagues cannot answer vendor or
  Finance questions about an invoice, or check where its payment stands.
- **No team view.** A department cannot see its own outstanding invoices, its queries from Finance
  or its payment pipeline in one place. The dashboard and reports show each person only their own
  share.
- **Workarounds.** Colleagues forward screenshots and emails, or ask Finance to look records up.
  Yesterday's change gave Chandru Manoharan view access to five named colleagues for exactly this
  reason. A department-wide rule removes the need to handle such requests one name at a time.

**Opportunity.** Making the department the natural boundary of visibility matches how the business
is actually organised. It improves continuity and gives each department a complete view of its own
spend, without granting anyone the ability to change another person's record.

**Compliance.** The boundary between departments is preserved. A user still cannot see another
department's invoices, so information stays within the team that owns it.

---

## Affected Business Areas

### Departments and teams

- **All operating departments**, including Customs Clearance, Freight Forwarding, Service Centre,
  Land Department, Warehouse, Yard, Cold Chain, HR, Sales, QHSE, IT, Pricing – Procurement, and
  Strategy & Analytics. Their members gain sight of their department's invoices.
- **Finance and Administrators.** No change. They already see everything.
- **Administration / IT.** A user's department now governs what that user can see. Keeping it
  accurate on the user record becomes an access-control duty, not just a profile detail.

### Users

- Every active user who has a department and is not Finance or Administrator. That is roughly 80
  people across requesters and approvers.
- Three approvers have no department on their record: Kareem Bahgat, Maher Aboud and Ahmad Aboud.
  They see no department's invoices until one is set. Their own access is unchanged.

### Business processes

- **Invoice follow-up and enquiries.** Any member of the department can answer them.
- **Submission, editing, cancellation, ERP posting, queries, payment-request creation, approval and
  payment.** All unchanged. Each remains with the invoice's owner, Finance, the assigned approver or
  Administrators.
- **User administration.** Changing someone's department now changes what they can see,
  immediately.

### Screens and reports

| Screen | Effect for a regular user |
|--------|---------------------------|
| Invoice Log | Lists every invoice raised under their department, plus their own. The submitter's name is already shown on each row. "My invoices" still shows only their own. |
| Invoice detail and attachments | Viewable for their department's invoices. No edit, cancel, delete, correction or upload controls appear on records that aren't theirs. |
| Payment Requests list, detail and PAF PDF | Includes every PAF holding one of their department's invoices. |
| Dashboard | Becomes a department dashboard, covering totals, status breakdown, trend and top vendors for their department. |
| Reports and exports | Cover their department's invoices. |
| Approvals queue | Unchanged. |
| Documents attached to a PAF as a whole | Unchanged. Visible only to Finance, Administrators and that PAF's approvers. |

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** The platform is fully operational. The current behaviour is the intended design
and poses no security or compliance risk.

### Workaround Availability

**Assessment:** Yes

**Justification:** Colleagues and Finance can share information by hand. Named view grants, as
introduced for Chandru Manoharan, can also bridge urgent individual cases in the meantime.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delay prolongs a manual, person-dependent follow-up process. It causes no
financial loss, reputational damage or regulatory exposure.

### Timeline Constraints

**Assessment:** No

**Justification:** No external deadline or audit date applies.

**Emergency Change Classification:** **No**

**Reason:** This is a planned enhancement with available workarounds and no time pressure. It should
follow the standard change process.

---

## Risk Assessment

### Risk Level

**Medium**

The change is read-only and stays inside each department's boundary. The rating reflects its
breadth: it widens access for almost every regular user at once, and it changes the visibility rule
that every screen relies on.

### Risks Identified

**1. Read access turning into write access (control). Most serious if realised.**
If being able to see a colleague's invoice were confused with owning it, users could edit, cancel
or delete their colleagues' records. In the current design, all such actions are tied to ownership,
role or approval assignment, never to visibility, so the likelihood is low. Testing must prove it
for each action.

**2. Leakage across departments (security / confidentiality).**
If the rule is implemented loosely, for example matching department names inexactly or treating a
missing department as matching everything, users could see other departments' invoices. Two edge
cases need care:

- Users who have no department must match nothing.
- Department names must be compared consistently. One user record is spelled "Freight forwarding"
  while the master list says "Freight Forwarding".

**3. Department chosen on the invoice differs from the submitter's own department (business).**
When raising an invoice, the submitter picks a "Submitting department" freely from the full list.
**67 of the 1,001 invoices on record (about 7%) are filed under a department other than the
submitter's own.** For example:

- one Yard user files Land Department invoices (16 invoices);
- one Service Centre approver files PDI invoices (37);
- one Service Centre user files Accessories invoices (8).

Whichever department is used decides who sees these invoices. See Decision 1.

**4. Departments with invoices but no members (operational).**
PDI (37 invoices) and Accessories (8) have invoices but no users assigned to them. Under the
department rule, nobody new would see those invoices; only the submitter, Finance and Administrators
would. See Decision 2.

**5. Department-sensitive spend becoming team-visible (confidentiality).**
Within a department, invoices that were effectively private to one person become visible to the
whole team. For most departments that is the intent. Department heads should confirm that no
category of invoice, for example in HR, must stay restricted to its submitter.

**6. Mixed-department payment requests (confidentiality, existing behaviour).**
A payment request can group invoices from several departments; 4 of the 251 on record do. A user
who can see such a request sees all the invoice lines on it, including other departments'. This is
already true today for the submitter of any invoice on the request. The change extends it to the
submitter's department colleagues.

**7. Department becoming an access-control field (governance).**
Moving a user between departments now moves their visibility with them, instantly. An incorrect or
outdated department on a user record means incorrect access.

**8. Inconsistent figures between screens (operational).**
The Invoice Log, Payment Requests, dashboard and reports must all change together, or totals will
not reconcile.

### Risk Mitigation Plan

1. **Read-only by construction.** The department rule is consulted only where the platform decides
   what a person may *see*. Automated tests prove that a department colleague is refused every edit,
   cancel, delete, correction, upload, posting and approval action. Mitigates risk 1.
2. **Strict matching.** A user with no department matches nothing. Department names are compared
   without regard to capital letters, and the one mis-cased user record is corrected through the
   normal user screen. Tests cover a user in another department, a user with no department, and
   differently cased names. Mitigates risk 2.
3. **An explicit, agreed rule for which department counts** (Decision 1), communicated to users so
   they understand that the department they pick on an invoice decides who can see it. Mitigates
   risk 3.
4. **Department heads confirm before go-live** that team-wide visibility is acceptable for their
   department's invoices. Mitigates risk 5.
5. **Treat a user's department as an access setting.** Department changes are already recorded in
   the audit trail. The periodic access review should include it. Mitigates risk 7.
6. **Change every screen in one release** and test that totals reconcile. Mitigates risk 8.
7. **Keep yesterday's named view grants** for cross-department needs. They are unaffected and
   complementary.

---

## Expected Business Impact

### Positive Impact

- **Continuity.** Any member of a department can follow up its invoices when the submitter is away.
- **Team-level oversight.** Each department sees its own outstanding invoices, Finance queries and
  payments, and gets a department dashboard and department reports.
- **Fewer interruptions** for Finance and for individual submitters.
- **Less administration.** Most "please let me see my team's invoices" requests are met without a
  named grant.
- **No role inflation.** Visibility widens without granting Finance or Administrator powers.

### Potential Negative Impact

- Information previously visible to one person becomes visible to their whole department.
- A user's department becomes access-relevant, so record accuracy matters more than before.
- Some users may at first be surprised by a much longer Invoice Log. "My invoices" remains
  available to narrow it.

### User Impact

- **Regular users.** On release, their Invoice Log, Payment Requests, dashboard and reports include
  their department's invoices. No new screens and no retraining are needed. A short notice should
  explain the change.
- **Approvers.** The same department view. Their approval queue and authority are unchanged.
- **Finance and Administrators.** No change.
- **No one needs to sign in again.**

### Reporting Impact

- No report is added or restructured, and no column or export format changes.
- For regular users, reports and dashboard figures widen from "mine" to "my department".
- Exported files leave the platform. The wider export scope is part of the confidentiality decision
  above.

### Compliance Impact

- **Segregation of duties preserved.** The change is read-only and adds no approval authority.
- **Departmental confidentiality preserved.** Users still cannot see other departments' invoices.
- **Auditability.** Changes to a user's department are already audited. No new personal data is
  involved, and retention is unchanged.

---

## Implementation Overview

The platform's central visibility rules for invoices and payment requests are extended. A regular
user sees:

- their own invoices;
- invoices raised by members of their department (the submitter's own department);
- invoices of any colleagues they hold a named grant for;
- for approvers, the invoices routed to them.

A user sees a payment request if it holds any invoice they can see. Every screen draws on these
central rules, so the Invoice Log, detail screens, attachments, Payment Requests, PAF PDF, dashboard
and reports all change together.

Nothing that decides what a user may *do* is touched. Editing, cancelling, deleting, correcting,
uploading, posting, approving and paying keep their existing ownership, role and approval checks.

No new data is stored, no record is altered, and no data migration is needed. Before go-live, one
mis-cased department on a user record is corrected through the normal user screen. Automated tests
are added, and the knowledge base is updated.

---

## Rollout Plan

1. **Development.** Extend the visibility rules, then add automated tests. The tests cover:
   - same-department visibility;
   - no visibility across departments;
   - users without a department;
   - department-name casing;
   - every write and approval action that must stay refused.
2. **Internal Validation.** On test accounts in two departments, confirm visibility on every screen,
   that totals reconcile, and that Finance and Administrator views are unchanged.
3. **QA Verification.** Run the full automated suite. Sign-off requires the "cannot see another
   department" and "cannot change a colleague's record" checks to pass.
4. **User Acceptance Testing.** A user from Customs Clearance and one from another department
   confirm what they can and cannot see. A department head confirms the department dashboard.
5. **Production Deployment.** Correct the mis-cased user department, deploy the release, and send
   the user notice.
6. **Post Deployment Monitoring.** For two weeks, collect feedback from departments, spot-check
   cross-department isolation, and review user records with missing or outdated departments.

---

## Backout Plan

1. **Suspend new functionality.** Redeploy the previous version. Visibility immediately returns to
   "own invoices only" (plus named grants).
2. **Restore previous application state.** No data was changed, so nothing needs unwinding.
3. **Restore backups if required.** Not expected; the standard pre-deployment backup is taken
   regardless.
4. **Validate business operations.** Confirm submission, posting, PAF creation, approval and
   payment work normally, and that each role's access is as before.
5. **Notify stakeholders.** Inform department heads, Finance and IT of the reversal.

---

## Decisions Requested from Approvers

1. **Which department makes an invoice visible?**
   - **(a) The department chosen on the invoice** *(recommended)*. This is what the request
     describes ("an invoice under the Customs department"), and it is the cost centre Finance
     reports by. The submitter still always sees their own invoice.
   - **(b) The submitter's own department.** This keeps invoices with the submitter's team even
     when they are filed for another department.

   This affects 67 existing invoices (about 7%). Under (a), the 16 Land Department invoices filed by
   a Yard user become visible to Land Department and not to Yard.
2. **PDI and Accessories** have invoices but no users. Should these be treated as part of another
   department (for example Service Centre), or left as they are? *(Recommended: leave as they are.
   Finance and Administrators still see them, and anyone who needs them can be given a named
   grant.)*
3. **Approvers.** Should approvers also see their department's invoices, alongside the requests
   routed to them? *(Recommended: yes. The request says "users", and approvers are typically
   department managers.)*
4. **Named view grants** (introduced yesterday for Chandru Manoharan). Should they remain for
   cross-department needs? *(Recommended: yes. They are now redundant for Chandru's four colleagues,
   who share his department, but they stay useful across departments.)*

---

## Approval Outcome and Implementation Status

**Approved to proceed:** 2026-10-07, with these decisions:

1. **Option (b): the submitter's own department** decides who sees an invoice, not the department
   picked on the invoice. The invoice stays with the team that raised it. If the submitter moves
   department, their invoices move with them, and a former member's invoices stay with the
   department.
2. **PDI and Accessories** are left as they are. Under (b) the question largely falls away: their
   invoices were raised by Service Centre staff, so Service Centre sees them.
3. **Requesters only.** *(Revised on 2026-10-07, after implementation; this replaces the earlier
   recommendation to include approvers.)* Department visibility applies only to users with the
   **Requester** role. Approvers, Finance and Administrators keep exactly the access they had
   before this change. A requester sees invoices raised by anyone in their department, whatever
   that person's role.
4. **Named view grants** are kept for cross-department needs.

**Implemented** as described. Ten automated tests cover:

- same-department visibility on every screen;
- isolation from other departments;
- the submitter's-department rule;
- blank departments and name casing;
- former members and department moves;
- every write and approval action that must stay refused;
- requesters only, with approvers unchanged (including that an approver still does not see a
  request merely because it holds their own invoice);
- unchanged Finance access.

The full suite shows no new failures.

**Results on live data (read-only check):** Chandru Manoharan now sees all 280 Customs Clearance
invoices, up from 127 of his own plus the 66 from his named grants. All 21 approvers were checked
against the rule from before this change and match it exactly. A Yard user's Land Department
filings stay with Yard. The mis-cased "Freight forwarding" user matches her department without any
data correction, so the planned pre-go-live record fix is optional.

---

## Approval Requirements

### Requestor

*(To be confirmed.)*

### Department Manager

Required, from each department head or through a single nominated business owner. Confirms that
team-wide visibility is acceptable for their department's invoices (Risk 5).

### IT Manager

Required. Confirms the approach, the read-only guarantee, and the testing and backout plans.

### Business Owner (Finance / Accounts Payable)

Required. Owns the invoice and PAF registers, and should confirm Decision 1.

### CAB Approval

Recommended. The change is technically small, but it alters access to financial records for almost
all users at once.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-07

Risk Rating: **Medium.** The change is read-only and confined to each department, but broad: it
affects every requester (47 active) at once.

Emergency Change: **No**

Analysis Confidence: **91%**

- **Affected Features Confidence: 95%.** Every screen draws on the two central visibility rules,
  which were traced in the source.
- **Business Impact Confidence: 88%.** Depends on Decision 1 and on department heads' confidentiality
  expectations.
- **Security Impact Confidence: 93%.** Write and approval checks are independent of visibility. The
  edge cases (missing department, name casing) were identified from live data.
- **Risk Assessment Confidence: 89%.** Department-record accuracy is an ongoing governance matter
  that cannot be verified from code.

---

## Technical Analysis Appendix

*For the implementation team and IT reviewers. Business approvers do not need to read it.*

### Affected Systems

- **The two central visibility rules (invoices, payment requests).** Every list, detail screen,
  attachment download, PAF PDF, the dashboard, reports, exports and the key-authenticated export
  API read them. Since the previous change, the detail and attachment checks call the central rule
  directly, so no second copy needs updating.
- **Not touched:** ownership, role and approval-assignment checks on every write action; the
  request-level document rule; the approval queue.

### Data Storage

No change. The invoice and the user already record a department, both drawn from the Departments
master list. No migration is needed.

### Data Observations (live database, read-only, 2026-10-07)

- 1,001 invoices across 16 departments; 67 are filed under a department other than the submitter's.
- One user department value differs from the master list only in capitalisation ("Freight
  forwarding"). The live database compares case-insensitively, but the rule should not depend on
  that.
- Three active approvers have no department.
- 4 of 251 payment requests contain invoices from more than one department.
- PDI and Accessories have invoices but no assigned users.

### Security Controls

Authentication is unchanged. Authorisation is a read-only widening bounded by department. A missing
department matches nothing. Every write and approval check is unchanged and covered by tests.

### Relationship to Other Change Requests

[grant-view-only-access-to-paf-records-of-nominated-colleagues.md](grant-view-only-access-to-paf-records-of-nominated-colleagues.md)
added named, cross-person view grants. That mechanism remains and complements this one.
