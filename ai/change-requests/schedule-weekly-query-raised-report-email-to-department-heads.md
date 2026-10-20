# Change Request

## Subject

Schedule an automatic weekly Query Raised Report, emailed every Friday to each Department Head

---

## Executive Summary

When Finance cannot post an invoice to the ERP because something is missing or wrong (a missing
document, an incorrect amount, a wrong job number), they raise a query on it. The invoice is then
marked **Query Raised** and is sent back to the employee who submitted it for correction. Until it
is corrected and resubmitted, the invoice cannot be posted, cannot go into a payment request, and
the vendor cannot be paid.

Today, the only person told about a query is the employee who submitted the invoice. Department
Heads have no routine view of how many of their department's invoices are on hold because of a
query, how long they have been waiting, or why they were queried. Queries can sit unanswered for
weeks. Department Heads usually find out when a vendor complains about late payment, or when Finance
escalates the matter by phone or email.

This change introduces an automatic **weekly Query Raised Report**. Every Friday at 08:00 Dubai
time, the Head of each department that has open queries gets one email covering their own
department. It lists every invoice in that department still awaiting a response to a Finance query:
the invoice number, PAF number, vendor name, invoice amount, submitter name, the query Finance
raised, any remarks, and how many days the query has been open. Departments with no open queries
receive nothing that week. The email carries no attachments.

To make this possible, the system needs to know who each department's Head is. It does not record
this today. The change therefore also lets the system administrator nominate one or more Department
Heads for each department in Master Data.

The expected outcome: queries are resolved faster, vendors are paid on time more often, Finance
spends less time chasing, and Department Heads can see and manage their department's backlog.

---

## Business Reason for Change

**Business challenge.** An invoice under query is stuck. It cannot move forward until the submitting
department acts, but nobody with authority over that department is routinely told about it. Today
the system reminds people about unanswered *approval* queries every two days. It sends no reminder
or escalation for Finance queries on invoices, so an unanswered query can sit indefinitely.

**Operational need.** Finance spends time manually chasing departments for answers and sometimes
builds ad-hoc lists of outstanding queries by hand. A scheduled report removes that manual effort.
It also gives every department the same information at the same time each week.

**Vendor relationship and cost.** Late answers to queries delay vendor payments. That can lead to
late-payment complaints, strained supplier relationships, held shipments, or lost early-payment
terms. Making the backlog visible to Department Heads every week is a low-cost way to reduce this.

**Governance and accountability.** Department Heads are accountable for their department's spending.
A regular, consistent report gives them evidence of their department's open issues. It also gives
management a record that outstanding queries were brought to the responsible manager's attention.

**Why Friday.** A Friday report lets Department Heads review the backlog at the end of the working
week and assign corrections, so they can be done early the following week.

---

## Affected Business Areas

**Departments**

- All operating departments that submit invoices through the PAF system (currently including
  Warehouse, Yard, Freight Forwarding, Custom Clearance, Land Transportation and Service Center).
- Finance, which raises the queries reported on.

**Users**

| Group | How they are affected |
|---|---|
| Department Heads | New: receive one email every Friday for their department, but only in weeks when it has open queries |
| Invoice submitters (requesters) | No change to their screens. Their outstanding queries become visible to their Head |
| Finance | No change to how queries are raised. New: receives one weekly summary of what was sent (not the departments' reports themselves). Fewer manual follow-ups expected |
| System Administrator | New: nominates and maintains the Department Head(s) for each department in Master Data |
| Approvers | Not affected |

**Processes**

- The invoice query process (Finance raises a query → submitter corrects and resubmits). The process
  itself does not change. It gains a weekly management-visibility step.
- Master Data maintenance gains one new item: Department Head nomination.

**Reports**

- New: the weekly Query Raised Report email (no attachments).
- New: the weekly summary of what was sent, for Finance and the administrator.
- The existing Reports page, Excel export and reporting feed are **not changed**. The weekly report
  uses the same invoice details and the same "Query Raised" status definition, so the email and the
  Reports page will always agree.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No

**Justification:** The invoice query process works today. This change adds visibility and escalation;
it does not fix a failure that is stopping business operations.

### Workaround Availability

**Assessment:** Yes, a workaround exists

**Justification:** Finance or a Department Head can filter the Reports page by the "Query Raised"
status and their department, then export the result to Excel by hand. This takes effort, depends on
someone remembering to do it, and needs the Head to have suitable system access. It is still a
workable stop-gap.

### Operational Impact

**Assessment:** No

**Justification:** Delaying this change keeps the current level of query delays. That is inconvenient
and has a cost, but it does not create new operational, financial or reputational damage.

### Timeline Constraints

**Assessment:** No

**Justification:** There is no regulatory or contractual deadline. The change can follow the normal
change assessment and approval process.

**Emergency Change Classification:** No

**Reason:** This is a planned business improvement with a working manual workaround. It should follow
the standard change process and be scheduled for the next normal release.

---

## Risk Assessment

### Risk Level

**Low to Medium (overall: Medium)**

The change adds a new outgoing report and does not alter how invoices, queries, approvals or payments
are processed. The rating is Medium rather than Low because the report automatically sends vendor
and invoice financial information by email, and it depends on the Department Head list being kept
accurate.

### Risks Identified

**Business Risks**

| Risk | Likelihood | Impact |
|---|---|---|
| A department has no Head nominated, so nobody receives its report and its queries stay invisible | Medium | Medium |
| The wrong person is nominated as Head (or a Head leaves and is not replaced), so the report goes to the wrong person or nobody | Medium | Medium |
| Department Heads see the email as noise and ignore it, so the change delivers no benefit | Low | Low |
| Department names on older invoices do not exactly match the Master Data list, so some invoices are left out of the report | Medium | Medium |

**Operational Risks**

| Risk | Likelihood | Impact |
|---|---|---|
| The automatic scheduler or email delivery service is not running on Friday, so no report goes out and nobody notices | Low | Medium |
| Email delivery problems (e.g. a mail-server password change) stop the report from being delivered | Low | Low |
| Friday is a short or non-working day for some departments, so the report is not read until the following week | Medium | Low |

**Security Risks**

| Risk | Likelihood | Impact |
|---|---|---|
| Vendor names, invoice amounts and query details leave the system by email and can be forwarded further | Medium | Medium |
| A Department Head receives the department's full list even though their normal system access might show them less | Medium | Low |
| An email address is entered incorrectly, sending financial information to an unintended or external recipient | Low | High |

### Risk Mitigation Plan

1. **Heads must be existing, active PAF users.** Department Heads are chosen from the list of active
   internal system users, not typed in as free-text email addresses. This removes the risk of a
   mistyped or external address. When a Head's account is deactivated, they automatically stop
   receiving the report.
2. **Only the administrator can nominate Heads**, and every change to a nomination is recorded in the
   system audit log.
3. **One department per email.** Each Head receives only their own department's invoices, never
   another department's. Where several Heads share a department, each is copied privately (BCC), so
   addresses are not exposed.
4. **No coverage gaps.** Each Friday, Finance and the administrator get one short summary of what was
   sent: which departments were reported to, who received each report, and how many open queries
   each contained. It also lists any department with open queries but **no Head nominated**, and
   any report that could not be delivered. Nothing is left out silently. The summary does not
   repeat the invoice details.
5. **Department name matching** compares names without regard to capitalisation or extra spaces.
   Any invoice whose department still matches nothing in Master Data is listed in the weekly
   summary to Finance, so it can be corrected rather than silently left out.
6. **Proof of delivery.** Every weekly run is recorded in the scheduler log and the system audit log.
   A run that never happened can then be told apart from a week with nothing to report.
7. **Retries.** Email uses the existing retrying delivery service, so short mail outages do not lose
   reports.
8. **No attachments.** The email has no attachments of any kind: no spreadsheet, and no supporting
   invoice documents (PDFs, scans). It includes only the details a Head needs to act, and links
   back to the system, where normal access controls apply.
9. **Send time and day are configurable**, so the schedule can be changed without a new release if
   Friday proves unsuitable.

---

## Expected Business Impact

### Positive Impact

- Queries are answered faster because the accountable manager can see them every week.
- Fewer late vendor payments caused by unanswered queries.
- Less manual chasing by Finance, with no hand-built lists of outstanding queries.
- A consistent, auditable record that outstanding queries were escalated to department management.
- Department Heads can spot recurring problems (the same vendor or the same missing document) and
  address the cause.

### Potential Negative Impact

- One more email per week for each Department Head.
- The report is only as good as the Department Head list. If that list is not maintained, the report
  goes to the wrong person or nobody.
- Invoice financial data is sent by email, which is less controlled than viewing it in the system.

### User Impact

- **Department Heads:** one email on Friday mornings. No new screens, and no action needed in the
  system unless they want to look into an invoice.
- **Administrator:** a one-off task to nominate Heads for every department, then occasional updates
  when people change roles.
- **Requesters, Finance and Approvers:** no change to the screens or steps they use today.

### Reporting Impact

- Adds a new scheduled report. Existing reports, exports and the reporting feed are unchanged.
- The weekly report uses the same "Query Raised" definition and invoice details as the Reports page,
  so figures will match.
- The report shows how long each query has been open, which the business does not easily see today.

### Compliance Impact

- Improves the control environment by evidencing that outstanding items are escalated to the
  responsible manager.
- Increases the amount of financial information sent by email, though only in the body of internal
  emails to nominated Heads. No attachments are sent. This should still be confirmed against the
  company's data-handling and email policies.
- No change to user access rights, approval authority or segregation of duties.

---

## Implementation Overview

1. **Department Head nomination.** Add a "Department Head(s)" setting to each department in the
   existing Master Data area. The administrator picks one or more active system users. Heads can
   also be filled in through the departments spreadsheet upload. Finance can see who the Heads are
   but cannot change them. Changes are audit-logged.
2. **Weekly report.** Every Friday at **08:00 Dubai time** (configurable), the system gathers every
   invoice currently in **Query Raised** status, groups them by department, and sends each
   department's list to its Head(s).
3. **Email content.** One line per invoice, oldest query first, with these columns:

   | Column | What it shows |
   |---|---|
   | Invoice Number | The vendor's invoice number |
   | PAF Number | The Payment Approval Form (PAF-YYYY-NNNNN) the invoice is or was on, if any. Blank if it has never been on one, which is usual, since Finance queries an invoice before it goes into a PAF |
   | Vendor Name | The vendor |
   | Invoice Amount | The invoice total, in the invoice's own currency |
   | Submitter Name | The employee who submitted the invoice |
   | Query Raised | What Finance asked, and the date it was raised |
   | Days Open | How many days the query has been waiting |
   | Remarks | Any remarks recorded on the invoice (the same Remarks shown on the Reports page, such as approver comments from an earlier PAF). Blank if none |

   A summary at the top gives the number of open queries and their value per currency. Each invoice
   links to its page in the system. **No attachments** are included.
4. **Only departments with open queries are emailed.** If a department has no open queries that
   week, its Head receives nothing.
5. **Weekly summary of what was sent.** One short email goes to Finance and the administrator after
   each run. It lists the departments reported to, the recipients, and the number of open queries in
   each report, plus any failures and any department with open queries but no Head nominated.
   Finance does **not** receive copies of the departments' reports.
6. **Run on demand.** IT can trigger the report manually for testing, UAT and catch-up after an
   outage, without waiting for Friday.

The change reuses the system's existing scheduling, email delivery and report definitions. It needs
no new supplier, licence or integration.

### Confirmed stakeholder decisions

| # | Decision | Agreed |
|---|---|---|
| 1 | Send time | Every Friday at 08:00 Dubai time |
| 2 | Departments with no open queries | No email is sent to that department's Head |
| 3 | Report columns | Invoice number, PAF number, vendor name, invoice amount, submitter name, the query raised, days open, and remarks (if any) |
| 4 | Attachments | None |
| 5 | What Finance receives | Only the weekly summary of what was sent |

**Out of scope:** payment requests that an *approver* has queried (approval queries). These already
get their own reminder every two days, and could be added to this report later as a separate change.

---

## Rollout Plan

1. **Development.** Build the Department Head setting, the weekly report and its email, and the
   weekly summary of what was sent.
2. **Internal Validation.** The development team checks the report against the Reports page filtered
   to "Query Raised", department by department, to confirm the lists match.
3. **QA Verification.** Automated and manual checks: correct department grouping, correct recipients,
   BCC for multiple Heads, no email for departments with no open queries, the 08:00 Friday timing,
   all report columns present, no attachments, the weekly summary flagging departments without a
   Head, inactive Heads excluded, and the existing query, approval and reporting processes unaffected.
4. **User Acceptance Testing.** Selected Department Heads and Finance receive test reports (run on
   demand) and confirm the content and layout meet their needs.
5. **Production Deployment.** Deploy, then the administrator nominates Heads for every department and
   corrects any mismatched department names listed in the first weekly summary. Confirm the scheduler and
   email delivery service are running.
6. **Post Deployment Monitoring.** For the first three Fridays, IT confirms in the scheduler log and
   audit log that the report ran and was delivered. Finance confirms receipt of the weekly summary
   and follows up on any department without a Head.

---

## Backout Plan

1. **Suspend new functionality.** Switch the weekly report off in configuration. This stops future
   emails immediately and needs no new release.
2. **Restore previous application state.** If needed, redeploy the previous release. The Department
   Head setting can stay in place unused. It does not affect any other process.
3. **Restore backups if required.** Not expected. The change does not modify invoices, queries,
   approvals or payments. The only new data is the list of Department Heads.
4. **Validate business operations.** Confirm that invoice queries, approvals, payments, the Reports
   page and the existing daily reminders still work as before.
5. **Notify stakeholders.** Tell Department Heads and Finance that the weekly report is paused, and
   point them to the manual workaround (Reports page filtered to "Query Raised") until it is restored.

---

## Approval Requirements

### Requestor

Name: ________________________  Signature: ________________  Date: __________

### Department Manager

Name: ________________________  Signature: ________________  Date: __________

### IT Manager

Name: ________________________  Signature: ________________  Date: __________

### Business Owner

(Finance — owner of the invoice query process)

Name: ________________________  Signature: ________________  Date: __________

### CAB Approval (if applicable)

Required: Recommended. The change starts a new regular distribution of financial information by
email to a new group of recipients.

Name: ________________________  Signature: ________________  Date: __________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 2026-10-20

**Risk Rating:** Medium

**Emergency Change:** No

**Analysis Confidence:** High

| Confidence Area | Rating |
|---|---|
| Analysis Confidence | High. The query status, the shared report definition, scheduling and email delivery were all confirmed in the current system |
| Affected Features Confidence | High. The change is additive and touches no existing workflow |
| Business Impact Confidence | Medium-High. The benefit depends on Department Heads acting on the report and on the Head list being kept up to date |
| Security Impact Confidence | Medium-High. Recipient control is strong when Heads are chosen from active users, and no attachments are sent. Policy on financial detail in email bodies still needs confirming |
| Risk Assessment Confidence | Medium-High. The scale of department-name mismatches on existing invoices is not yet known. The weekly summary lists any mismatch from the first run |

---

# Technical Analysis Appendix

*Included only to explain risk, effort and dependency. Kept deliberately brief.*

**Affected systems**

- Master Data (Departments), to hold the nominated Department Head(s).
- The system's scheduled jobs. It already sends daily approval reminders at 09:00. The weekly
  report joins that schedule at its own time, 08:00 on Fridays.
- The existing shared invoice report definition and its "Query Raised" status, reused so the email
  matches the Reports page.
- The existing email delivery service, which queues and retries messages.

**Key finding: no Department Head exists today**

The system records each user's department as text and keeps a master list of departments. It has no
concept of a Department Head. Heads must be nominated before this report can go anywhere, which is
why nomination is part of this change and not an assumption.

**Security controls**

- This report is produced by the system on a schedule, not by a logged-in user. So it does not
  follow any one viewer's normal screen access. Instead, access is governed by the Head nomination
  itself, which is administrator-only, limited to active internal users, and audit-logged.
- Each email contains one department's data only. Multiple recipients are BCC'd.
- No attachments of any kind: no spreadsheet and no supporting invoice documents.

**Dependencies**

- The scheduler (run every minute by the server's task scheduler) and the email delivery worker must
  both be running in production. Both are already needed for the existing daily reminders, so no new
  infrastructure is required. Post-deployment monitoring confirms them on the first Fridays.

**Effort**

Small to moderate. Most of the work is the Department Head nomination screen and the email layout.
The report data and delivery reuse existing components. Verification focuses on correct recipients
and department grouping.
