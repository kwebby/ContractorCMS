<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Implementation security review

This is a source and automated-test review performed during implementation, not an independent manual penetration test or an OWASP ASVS certification. External production-like testing remains required.

## Findings addressed

| ID | Original severity | Finding and remediation | Evidence |
|---|---|---|---|
| SEC-01 | High | Client job responses exposed internal field notes, checklists and costs. Shared client-safe projections now whitelist public scope/status fields in list, detail and dashboard responses. | app/Support/Access.php, OperationsController and WorkspaceController; SecurityReviewTest |
| SEC-02 | High | Role-name-only MFA gating missed office/custom financial permissions. Privilege detection now follows effective finance/payroll/settings/team permissions. | app/Support/Access.php, CmsSecurity.php; MfaSecurityTest and SecurityReviewTest |
| SEC-03 | High | Draft editor uploads could become public before publication. Draft media now requires publishing access and only promotes inside a successful publication transaction. Failed/stale publication rolls back promotion; new draft images stay private on already-live pages. | PublishingMediaTest: content, layout and social-image paths |
| SEC-04 | High | Reassigning an existing conversation attachment could change who could download it. Attachment linking now checks ownership/readiness and prevents movement between conversations inside the message transaction. | CommunicationsController, FilesController; SecurityReviewTest |
| SEC-05 | High | Delegated team managers could grant more privilege than they held. Role/custom-permission grants now require every granted permission to be held by the actor and require recent reauthentication. | Access::authorizeRoleGrant; SecurityReviewTest |
| SEC-06 | High | Backup restoration needed explicit protection against filesystem symlinks, populated targets and stale sessions. Restore now requires empty targets, AES-256, original key fingerprint, validated paths/hashes, no symlinks, and invalidates file sessions after successful restore. | BackupService::restore; encrypted roundtrip/symlink/session tests |
| SEC-07 | Medium | MFA challenges could persist too long or survive identity changes. Challenges expire after ten minutes, bind session versions, reject replayed codes, and bind enrollment to the current user. | AuthController; MfaSecurityTest and SecurityReviewTest |
| SEC-08 | Medium | Password reset left other reset links valid. A successful reset consumes all outstanding reset tokens for that user and increments the session version. | AuthController::reset; SecurityReviewTest |
| SEC-09 | Medium | Duplicate chat requests created repeated notifications. Messages, attachment linkage and notifications now share the idempotent transaction. | SecurityBoundaryTest, GuestChatTest |
| SEC-10 | High | Payment-return navigation could suggest success before settlement. Money remains derived only from verified provider/offline ledger events; the return path leads to invoice status without writing payment state. | FinanceWorkflowTest, PaymentGateway, SqlConcurrencyTest |

## Verified controls

- New registration cannot select staff roles or claim historical customer records. An explicit customer invitation links records; existing accounts must prove their password.
- Roles do not replace record relationships. Two-client isolation, technician assignment restrictions, employee-only payslips, private conversations and protected downloads have regression tests.
- Issued financial snapshots cannot be silently overwritten. Parallel SQL processes verify unique invoice numbering and idempotent payment application.
- Original Stripe payload/signature handling, PayPal verification, replay handling, settlement/refunds/reversals and reconciliation are covered with provider mocks.
- ZIP traversal, duplicates, symlinks, executable files, unsafe CSS/URLs and sandbox escapes are rejected. Twig receives primitive public context and a restricted tag/filter policy.
- BlockNote/layout output is escaped and bounded. Public scripts belong to the application. CSP uses a nonce and same-origin scripts; script unsafe-inline/unsafe-eval are not enabled. Inline style permission supports constrained layouts and the visual editor.
- Private pages use no-store/noindex; robots directives do not authorize access. Credentials are encrypted in database settings and masked in APIs.
- CSRF enforcement is exercised outside the framework's unit-test bypass. Production host/proxy handling is explicitly configured.
- Outbox leases recover abandoned work; retry failures retain exception classes rather than potentially sensitive provider messages.
- AI has no private CRM/payroll/chat context, bounded inputs/outputs, reserved quotas, structured responses, idempotency and separate consent. Provider failure is not presented as a successful analysis.

## Residual verification and scope

No independent assessor has tested this deployment. Real hosted Firestore, Supabase, SMTP, Stripe/PayPal sandbox accounts, AI credentials, clean Linux/shared hosting, full load/resource-exhaustion testing and disaster recovery on a second machine remain acceptance work.

Some lists/report calculations scan collections. Firestore filtered-query/index/reporting and backup certification remain incomplete. External PDF uploads stay quarantined without scanning. SMTP delivery is at-least-once. Public website assets cannot be retracted after they have been downloaded; the editor makes first-publication permanence explicit.

Run the required external test with authorized scope and synthetic data. Record every finding, severity, affected endpoint and reproduction; retest fixes. Release only after all Critical/High findings are resolved and the independent acceptance checklist passes.
