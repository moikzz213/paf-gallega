---
name: "change-request-generator"
description: "Generate management-friendly Change Request documents with business impact analysis, risk assessment, implementation planning, testing requirements, and rollback strategy. Use before implementing enhancements, bug fixes, security updates, integrations, or infrastructure changes."
---

# Change Request Generator

## Purpose

Generate enterprise Change Request (CR) documents that are suitable for:

- Executive Management
- Department Heads
- Project Sponsors
- Business Stakeholders
- Change Advisory Board (CAB)

The document must be written in clear business language and should be understandable by non-technical readers.

The AI may perform deep technical analysis internally, but the generated Change Request should focus primarily on:

- Business value
- Business impact
- Risks
- Benefits
- Implementation approach
- Operational impact

Avoid excessive technical terminology unless required to explain risk or impact.

---

# Workflow

## Phase 1 - Repository Analysis

Before generating the Change Request, analyze the codebase and determine the affected:

### Functional Areas

- Features
- Business Processes
- User Workflows
- Reports
- Dashboards
- Integrations

### Technical Areas

- Controllers
- Services
- Models
- APIs
- Database
- Security Components
- Infrastructure

### Compliance & Security

Analyze:

- Authentication
- Authorization
- Permissions
- Data Exposure Risks
- Audit Requirements
- Regulatory Implications

This analysis should be used internally to determine scope and risk.

Do NOT overwhelm the final Change Request with technical implementation details.

---

# Output Location

Store generated Change Requests under:

/ai/change-requests/

If the folder does not exist:

```bash
mkdir -p ai/change-requests
```

---

# Filename Rules

Generate filenames from the Change Request subject using kebab-case.

Example:

Add AI Forecast Confidence Score

becomes:

add-ai-forecast-confidence-score.md

Store as:

/ai/change-requests/add-ai-forecast-confidence-score.md

---

# Executive Writing Standards

The primary audience is senior management.

The generated document must:

✅ Focus on business outcomes

✅ Focus on operational impact

✅ Focus on benefits and risks

✅ Use plain English

✅ Explain technical changes in a business-friendly way

Avoid:

❌ Controller names

❌ API endpoint details

❌ Database schema descriptions

❌ Source code references

❌ Development jargon

Only include technical details when necessary to explain:

- risk
- effort
- dependency
- system impact

---

# Emergency Change Assessment

Answer the following using:

- Assessment (Yes/No)
- Business Justification

Questions:

1. Does the change address an immediate risk to business continuity, security, or compliance?

2. Is there no feasible workaround to mitigate the risk temporarily?

3. Would delaying the change cause unacceptable operational, financial, or reputational damage?

4. Do time constraints prevent following the normal change assessment process?

Determine:

Emergency Change Classification:
Yes / No

Business Reason:

---

# Risk Assessment

Determine:

- Low
- Medium
- High
- Critical

Include:

### Business Risks

### Operational Risks

### Security Risks

### Mitigation Actions

Risk descriptions should be business-oriented rather than technical.

---

# Confidence Scoring

Generate:

Analysis Confidence Score

Affected Features Confidence

Business Impact Confidence

Security Impact Confidence

Risk Assessment Confidence

---

# Change Request Template

# Change Request

## Subject

---

## Executive Summary

Provide a concise summary of:

- what is changing
- why the change is required
- expected business outcome

Maximum 5 paragraphs.

---

## Business Reason for Change

Describe:

- business challenge
- opportunity
- compliance requirement
- operational need

---

## Affected Business Areas

Identify:

- Departments
- Teams
- Users
- Processes
- Reports

Avoid technical file references.

---

## Emergency Change Assessment

### Business Continuity

Assessment:

Justification:

### Workaround Availability

Assessment:

Justification:

### Operational Impact

Assessment:

Justification:

### Timeline Constraints

Assessment:

Justification:

Emergency Change Classification:

Reason:

---

## Risk Assessment

### Risk Level

Low / Medium / High / Critical

### Risks Identified

### Risk Mitigation Plan

---

## Expected Business Impact

### Positive Impact

### Potential Negative Impact

### User Impact

### Reporting Impact

### Compliance Impact

---

## Implementation Overview

Provide a high-level implementation summary.

Do not include technical implementation details.

---

## Rollout Plan

1. Development
2. Internal Validation
3. QA Verification
4. User Acceptance Testing
5. Production Deployment
6. Post Deployment Monitoring

---

## Backout Plan

1. Suspend new functionality
2. Restore previous application state
3. Restore backups if required
4. Validate business operations
5. Notify stakeholders

---

## Approval Requirements

### Requestor

### Department Manager

### IT Manager

### Business Owner

### CAB Approval (if applicable)

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date:

Risk Rating:

Emergency Change:

Analysis Confidence:

---

# Technical Analysis Appendix

This section is optional.

Include only when necessary.

Possible Sections:

- Affected Systems
- Integrations
- Security Controls
- High-Level Architecture Impact

Keep technical detail minimal and management-friendly.

---

# Post Approval Process

If stakeholders approve the Change Request and provide instruction to proceed with implementation:

Automatically generate a Test Case document.

---

# Test Case Generation

Output Location:

/ai/test-cases/

If folder does not exist:

```bash
mkdir -p ai/test-cases
```

---

# Filename Rules

The Test Case filename MUST exactly match the Change Request filename.

Example:

CR:

/ai/change-requests/add-ai-forecast-confidence-score.md

Test Cases:

/ai/test-cases/add-ai-forecast-confidence-score.md

---

# Test Case Requirements

Generate:

# Test Cases

## Related Change Request

Reference the originating Change Request.

## Objective

## Scope

## Test Scenarios

### Happy Path Tests

### Negative Tests

### Security Tests

### Regression Tests

### User Acceptance Tests

---

## Expected Results

---

## Pass/Fail Criteria

---

## Test Execution Checklist

---

## Sign-Off

### QA Lead

### Business Owner

### UAT Sign-Off

---

# Traceability Rules

Every Test Case document must reference:

- Change Request Subject
- Change Request Filename
- Risk Rating
- Implementation Date

This ensures full traceability between:

Change Request → Development → Testing → Deployment