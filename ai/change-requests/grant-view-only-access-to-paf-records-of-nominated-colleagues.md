# Change Request

## Subject

Grant a user view-only access to the PAF records raised by nominated colleagues, with the list of
colleagues maintained by an Administrator.

---

## Executive Summary

Chandru Manoharan, Department Manager for Customs Clearance, handles the PAF follow-up for several
members of his team. Today he cannot see their submissions. The platform shows each requester only
their own invoices and the payment requests (PAFs) that contain them. Each enquiry, status check or
supporting document Chandru needs therefore has to go through the person who raised it, or through
Finance.

This change adds a new setting that an Administrator manages for any user. It is a list of
colleagues whose PAF records that user may **view**. With a colleague on the list, the user sees
that colleague's invoices, the attached documents and history, and the PAFs those invoices belong
to. The colleague's figures also appear in the user's dashboard and reports. Nothing else changes.
The user still cannot edit, cancel, delete, correct or attach anything to a colleague's record. The
grant also gives no approval authority.

The list can be changed whenever needed. The first five names are Umesh Chhetri, Sohaib Ali Anjum,
Muhammed Jabir, Ma. Theresa Endaya and Shameer Hussain Adyar. An Administrator can add or remove
names on the user-administration screen at any time, with no release required. Every addition and
removal is recorded in the audit trail. The same setting can be used for any other user who needs
to follow a team's work.

The recommended risk rating is **Medium**. The amount of information exposed is small: one manager
gains read-only sight of his own team's records. The rating is driven by the fact that the change
alters the rule deciding who can see which record, and that rule is shared by every user and every
screen. Done carelessly, it could widen access for people it was never meant to reach. Testing
targets exactly that risk.

---

## Business Reason for Change

**The operational need.** Chandru follows up PAF activity on behalf of his team. He answers
questions about it and tracks progress through approval and payment. The platform does not let him
see that work, so he relies on colleagues forwarding screenshots or emails, or on Finance looking
records up for him. This is slow, it interrupts the people who raised the records, and his view of
the work is always second-hand and possibly out of date.

**The current workarounds are worse.** There are two today:

- **Share a login.** This breaks individual accountability and the audit trail, and it is not
  acceptable.
- **Move Chandru into the Finance or Administrator role.** That would show him every record in the
  company. It would also let him post invoices to the ERP, raise queries, create and pay payment
  requests, and edit master data. Granting those powers to satisfy a narrow, read-only need is role
  inflation, and it would be an audit finding.

**The structural gap.** Today, visibility depends only on a person's role. There is no way to say
"this person may also see that person's records." The request names five colleagues, and it
explicitly asks that the list stay open: names must be addable and removable as the team changes.
The platform therefore needs a named, adjustable and auditable exception. A one-off fix for five
people would not meet that need.

**Why now.** The request comes from the business and affects day-to-day follow-up work. There is no
compliance breach or deadline, so this is a planned enhancement.

---

## Affected Business Areas

### Departments and teams

- **Customs Clearance.** Chandru gains read-only sight of the nominated colleagues' PAF records.
  The colleagues' own access is unchanged.
- **Administration / IT.** Gains a new setting on the user-administration screen, and owns the
  decision about who receives it.
- **Finance / Accounts Payable.** No change to their access. They should receive fewer look-up
  requests.
- **Approvers and all other staff.** No change.

### Users

- Chandru Manoharan, on release, for the five nominated colleagues.
- Any other user an Administrator later sets up in the same way.
- Every other account stays exactly as it is today.

### Business processes

- **Following up a team's PAFs.** The nominated manager can check status, documents and history
  directly.
- **Invoice submission, editing, cancellation, ERP posting, queries, payment-request creation,
  approval and payment.** All unchanged. These remain with the invoice owner, Finance, the assigned
  approvers and Administrators.

### Screens and reports

| Screen | Effect for the nominated user |
|--------|------------------------------|
| Invoice Log | Lists the colleagues' invoices alongside the user's own. The "My invoices" filter still shows only the user's own. |
| Invoice detail and its attachments | Viewable for the colleagues' invoices. No edit, cancel, delete, correction or upload controls appear. |
| Payment Requests list and detail, and the PAF PDF | Includes every PAF that contains a colleague's invoice. |
| Dashboard | Totals, status breakdown, trend and top-vendor figures include the colleagues' invoices. |
| Reports and report exports | Include the colleagues' invoices. |
| Approvals queue | Unchanged. Viewing is not approving. |
| Supporting documents attached to a PAF as a whole | Unchanged. They remain limited to Finance, Administrators and that PAF's approvers. See Risk 4. |
| Audit Log, User Administration, Approval Levels, Master Data | Unchanged. |

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** The platform is fully operational, and invoices and PAFs flow normally. The
current behaviour is the intended design. There is no security exposure and no compliance breach.

### Workaround Availability

**Assessment:** Yes. Workarounds exist, but none is suitable long term.

**Justification:** Colleagues or Finance can keep sending Chandru the information he needs by hand.
That is slow, but it is safe. Promoting him to Finance or Administrator would also work, but it
grants far more power than he needs. Shared logins are not acceptable.

### Operational Impact

**Assessment:** No unacceptable impact from delay

**Justification:** Delay keeps an inefficient follow-up process in place. It causes no financial
loss, reputational damage or regulatory consequence.

### Timeline Constraints

**Assessment:** No

**Justification:** No external deadline, audit date or month-end dependency applies. The standard
process can be followed in full.

**Emergency Change Classification:** **No**

**Reason:** This is a planned, contained enhancement to a working platform. A temporary manual
workaround exists and there is no time pressure, so it should take the normal change route.

---

## Risk Assessment

### Risk Level

**Medium**

The information exposed is limited: read-only access for one manager to his own team's PAF
records. The rating is Medium because of where the change sits. It touches the rule that decides
who can see which record, and every user and every screen depends on that rule. Confidentiality is
the risk being managed, not system stability.

### Risks Identified

**1. Read access turning into write access (control). Most serious if realised.**
If the new access were confused with ownership, the viewer might be able to edit, cancel, delete or
add documents to a colleague's invoice. The current design keeps these separate: editing depends on
being the record's owner, Finance or an Administrator, never on being able to see the record. The
likelihood is therefore low. The consequence would be serious, so testing must prove this rather
than assume it.

**2. Access widening for people it was not granted to (security).**
The visibility rule is shared by all users. If it were relaxed in general, instead of as a named
exception, other requesters could start seeing each other's records.

**3. The list growing unchecked (governance).**
The list is easy to extend. Without ownership and periodic review, names will accumulate. For
example, someone who moves to another team might still be visible to their former manager.

**4. Request-level documents being exposed (confidentiality).**
A PAF can group invoices from several people and departments. Documents attached to the PAF as a
whole can show figures for all of them. For that reason, today they are shown only to Finance,
Administrators and that PAF's approvers. They are not shown to requesters, even for a PAF that
holds the requester's own invoice. This change **keeps that restriction**: the nominated viewer
sees the PAF but not those documents, exactly as the colleague who raised the invoice does. If the
business wants the viewer to see them, that is a separate decision with a wider exposure.

**5. Inconsistent figures between screens (operational).**
The Invoice Log, Payment Requests, dashboard and reports must all widen together. If they don't, a
dashboard total will not agree with the list beneath it.

**6. Missing audit trail (compliance).**
Without a record of each grant and revocation, the organisation cannot show who could see whose
records, or since when.

**7. A named colleague without an account (delivery).**
**Shameer Hussain Adyar does not currently have a PAF account.** No user with that name exists on
the platform. His records cannot be shared until an account exists, and none exist to share. The
business should confirm whether he needs an account, or whether he is registered under a different
name. Ma. Theresa Endaya has an account but has not yet raised any invoices, so nothing of hers will
appear until she does.

### Risk Mitigation Plan

1. **Named, per-person exception.** The grant names the viewer and each colleague explicitly. It is
   never derived from role or department, so no one else is affected. Mitigates risk 2.
2. **Read-only by construction.** The grant is consulted only where the platform decides what a
   person may *see*, and nowhere it decides what a person may *do*. Automated tests prove that the
   viewer is refused every edit, cancel, delete, correction, document upload and approval action on
   a colleague's record. Mitigates risk 1.
3. **No onward sharing.** Seeing a colleague's records does not pass on that colleague's own grants.
   Mitigates risk 2.
4. **Administrator-only, audited.** Only Administrators can change the list, on the existing
   Administrator-only screen. Each addition and removal is written to the audit trail. Mitigates
   risks 3 and 6.
5. **Off by default.** Every existing and new account starts with an empty list. The release
   changes nothing until an Administrator acts. Mitigates risk 2.
6. **Keep the request-level document restriction as it is**, unless the business explicitly decides
   otherwise. Mitigates risk 4.
7. **Move all screens in one release**, and test that the dashboard, the lists and the exports
   reconcile. Mitigates risk 5.
8. **Periodic review.** Add the list to whatever access review the organisation already runs over
   user roles. Mitigates risk 3.
9. **Resolve Shameer Hussain Adyar's account before go-live.** Mitigates risk 7.

---

## Expected Business Impact

### Positive Impact

- **Faster follow-up.** Chandru can answer status questions and check documents himself, without
  waiting for colleagues or Finance.
- **Fewer interruptions** for the team members who raised the PAFs, and for Finance.
- **No role inflation.** The need is met without granting Finance or Administrator powers.
  Segregation of duties stays intact.
- **Reusable and self-service.** Future requests of this kind become an Administrator task, recorded
  in the audit trail. No new change request or release is needed.
- **Instantly reversible.** Removing a name withdraws access immediately.

### Potential Negative Impact

- A real but limited widening of who can see these colleagues' records, including amounts, vendors
  and attachments.
- An ongoing governance task: someone must own and periodically review who appears on these lists.
- One more setting for Administrators to understand when managing users.

### User Impact

- **Chandru.** On release, sees the five colleagues' invoices and the related PAFs alongside his
  own. The screens he already uses simply show more. No new screen and no retraining are needed.
- **The nominated colleagues.** No change to what they see or can do.
- **Administrators.** A new list on the user form to maintain.
- **Everyone else.** No change. No one needs to sign in again.

### Reporting Impact

- No report is added or restructured, and no column, filter or export format changes.
- For the nominated user, reports, report exports and dashboard figures now include the colleagues'
  invoices.
- Exported files leave the platform. This should be considered when deciding who to nominate.

### Compliance Impact

- **Segregation of duties is preserved.** The grant is read-only and confers no approval authority.
  Tests will show this.
- **Least privilege is respected.** The grant is far narrower than the alternative of promoting the
  user to Finance or Administrator.
- **Auditability.** Each grant and revocation is logged, so the organisation can answer "who could
  see whose PAFs, and since when?"
- No change to data retention, personal-data handling or regulatory reporting.

---

## Implementation Overview

Each user gets a new setting: a list of colleagues whose PAF records they may view. It is empty for
every account unless an Administrator fills it. Administrators maintain it on the existing
user-administration screen by picking colleagues from the user list. Names can be added or removed
at any time. Each change is recorded in the audit trail, next to the existing record of role,
department and status changes.

Next, the platform's existing visibility rules learn to respect the list. Those rules already let a
requester see their own invoices and the PAFs that contain them. The change makes them treat a
listed colleague's records the same way, for viewing only. Invoices and PAFs each have one central
rule, and the dashboard, reports and detail screens all draw on those rules. The effect therefore
reaches every screen consistently from a small number of changes. Two detail screens currently
repeat the rule rather than using the central one. They will be brought onto the central rule so
they cannot drift.

Editing, cancelling, deleting, correcting, uploading and approving are left untouched. They
already depend on ownership, role or approval assignment, never on visibility. The screens already
hide those controls from anyone who is not the record's owner, Finance or an Administrator.

Automated tests will cover what the grant allows and, just as important, what it must not allow.
The knowledge-base documentation will be updated. After release, an Administrator sets up Chandru's
list.

The release adds one small new data table and requires a normal deployment with refreshed front-end
assets. No existing record is altered.

---

## Rollout Plan

1. **Development.** Add the colleague list and its Administrator screen, record changes in the
   audit trail, and honour the list in the visibility rules for invoices and PAFs. Add automated
   tests covering both the access granted and every action that must stay refused.
2. **Internal Validation.** On test accounts, confirm the following:
   - A nominated viewer sees the colleagues' invoices and PAFs.
   - Dashboard, lists and exports reconcile.
   - No edit, cancel, delete, correction or upload control appears on a colleague's record, and
     each such action is refused if attempted directly.
   - The approval queue is unchanged.
   - A user without the setting sees no difference.
3. **QA Verification.** Run the automated test suite and the checks above. Sign-off is withheld
   until the "cannot change a colleague's record" and "no one else gains access" checks pass.
4. **User Acceptance Testing.** Chandru confirms on a test setup that he can follow his team's PAFs.
   One of the colleagues confirms their own access is unchanged. An Administrator confirms the list
   is easy to use and that changes appear in the audit log.
5. **Production Deployment.** Deploy during an agreed window. The release changes nothing on
   arrival because every list is empty.
6. **Grant access.** An Administrator adds Umesh Chhetri, Sohaib Ali Anjum, Muhammed Jabir and
   Ma. Theresa Endaya to Chandru's list. Shameer Hussain Adyar is added once his account exists or
   his registered name is confirmed. The Administrator then checks the audit entries.
7. **Post Deployment Monitoring.** For two weeks, confirm with Chandru that the access works as
   expected. Spot-check that no other user's access has changed.

---

## Backout Plan

1. **Withdraw the grant.** This takes seconds and needs no deployment. An Administrator clears
   Chandru's list, and his access returns at once to exactly what it was.
2. **Suspend the functionality.** Leave every list empty. The platform then behaves exactly as it
   does today.
3. **Restore the previous application state.** Redeploy the prior version and its front-end assets.
   The new table becomes unused. Removing it is optional and holds only the grants themselves.
4. **Restore backups if required.** This is not expected, because no existing record is modified.
   Take the standard pre-deployment backup regardless.
5. **Validate business operations.** Confirm that submission, posting, PAF creation, approval and
   payment work normally, and that each user's access is as before.
6. **Notify stakeholders.** Inform Chandru, the Customs Clearance team, Finance and IT of the
   reversal and the reason.

---

## Approval Requirements

### Requestor

Customs Clearance, on behalf of Chandru Manoharan *(name of the formal requestor to be confirmed)*.

### Department Manager

Required. Confirms that Chandru should see the nominated colleagues' PAF records, and who may
request future additions.

### IT Manager

Required. Confirms the approach, the read-only guarantee, the audit recording, and the deployment
and backout plans.

### Business Owner (Finance / Accounts Payable)

Required. Finance owns the invoice and PAF registers. Finance should confirm the grant and that
request-level PAF documents stay restricted (Risk 4).

### CAB Approval

Recommended. The change is technically small, but it creates a reusable access mechanism over
financial records. CAB should note the governance and periodic-review obligation.

---

## Decisions Requested from Approvers

1. **Shameer Hussain Adyar.** Should an account be created for him, or is he registered under
   another name?
2. **Request-level PAF documents.** Keep them hidden from the nominated viewer, as they are hidden
   from the colleagues who raised the invoices? *(Recommended: keep hidden.)*
3. **Former team members.** When a colleague leaves the team or is deactivated, should their past
   records stay visible until an Administrator removes them from the list? *(Recommended: yes. The
   Administrator removes the name as part of the move or exit.)*

---

## Approval Outcome and Implementation Status

**Approved to proceed:** 2026-10-06, with the recommended answers to the decisions above:

1. **Shameer Hussain Adyar.** Left out for now. He will be added manually once his account exists.
2. **Request-level PAF documents.** Kept hidden from the nominated viewer.
3. **Former team members.** Their records stay visible until an Administrator removes them from the
   list. This is recorded as a known limitation that the periodic access review should cover.

**Implemented** as described, with no divergence from this document:

- The colleague list, shown on the user form as "Can view PAFs raised by", is maintained by
  Administrators. The Users table also lists each person's grants, to support access reviews.
- Every change is audited.
- Visibility widens consistently across all the listed screens, and no write or approval right is
  added.
- 12 automated tests cover what the grant allows and what it must not allow.

Added at the requester's instruction during implementation: the Invoice Log now shows **who
submitted each invoice** under its submission date. Once one person can see several colleagues'
records, the list has to say whose each one is. The name is shown to every user; it adds no access.

One small related fix is included. Opening an invoice attachment without being signed in now
returns a clean "not signed in" response instead of a server error. No file was ever exposed.

**Initial grant applied:** Chandru Manoharan can now view the records of Umesh Chhetri, Sohaib Ali
Anjum, Muhammed Jabir and Ma. Theresa Endaya. The change is recorded in the audit trail. Shameer
Hussain Adyar is pending, as above.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-10-06

Risk Rating: **Medium.** The data exposure is narrow and read-only. The rating reflects that the
change alters the visibility rule that every user and screen relies on.

Emergency Change: **No**

Analysis Confidence: **92%**

- **Affected Features Confidence: 95%.** Every screen governed by the visibility rules was traced
  in the source, including the two detail screens that repeat the rule.
- **Business Impact Confidence: 90%.** The effect on Chandru is clear. How widely the mechanism will
  be reused is a management decision.
- **Security Impact Confidence: 94%.** Viewing and changing records are separate controls in the
  current design, and every write action checks ownership, role or approval assignment.
- **Risk Assessment Confidence: 89%.** The main long-term risk is governance discipline, which
  cannot be verified from the codebase. Shameer Hussain Adyar's account status needs business
  confirmation.

---

## Technical Analysis Appendix

*For the implementation team and IT reviewers. Business approvers do not need to read it.*

### Affected Systems

- **User administration.** A new viewer-to-colleague list is managed on the Administrator user
  form, and its changes are written to the existing user-change audit entry.
- **Central visibility rules for invoices and PAFs.** These are consulted by the Invoice Log, the
  Payment Requests list and detail, the PAF PDF, the dashboard, the Reports page, report exports and
  the key-authenticated export API. All of these widen together.
- **Two inline visibility checks** (invoice detail, invoice attachment download) that restate the
  rule. They will be switched to the central rule so the three cannot diverge.
- **Not touched:** ownership checks on edit, cancel, delete, correction and document upload, the
  approval-authority check, the approval queue, and the request-level document rule. Each already
  depends on ownership, role or chain membership, not on visibility.

### Data Storage

One new link table (viewer, colleague), managed only through the Administrator screen. No existing
table or row is altered. This is a forward-only, additive migration.

### Integrations

None. Existing internal endpoints keep their shape and return a wider result set for a nominated
viewer. The key-authenticated export API follows the visibility of the user its key belongs to, so
it widens in the same way.

### Security Controls

- **Authentication.** Unchanged.
- **Authorisation.** A read-only extension of visibility, granted per named pair.
- **No onward sharing.** A viewer does not inherit their colleagues' own grants.
- **Segregation of duties.** Unchanged, and covered by explicit automated tests.
- **Audit.** Grants and revocations are logged.

### Relationship to Other Change Requests

[grant-selected-approvers-company-wide-view-access.md](grant-selected-approvers-company-wide-view-access.md)
proposes a broader "View all records" permission for selected approvers. It has not been implemented.
This change is narrower and independent, and it does not depend on that one. If both proceed, they
coexist: one grants sight of everything, the other sight of named colleagues' records.

### Alternative Considered

**Department-wide view** ("may see everything raised in Customs Clearance") was considered and not
recommended. The request names individuals and asks for a list that can be adjusted. The department
currently has eight users, so a department-wide grant would automatically include people who were
not named, and anyone who later joins.
