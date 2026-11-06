# Accessibility review checklist

The dashboard includes basic accessibility support, but automated contract tests are not a WCAG audit or certification.

## Implemented checks
- The document declares Hindi as its primary language.
- Project tracking table has an accessible name, a caption, and column header scopes.
- Project and task dialogs reference their visible headings with `aria-labelledby`.
- Search and filter controls have accessible labels.
- Dynamic task checkboxes and project/task/approval action buttons include item-specific accessible names.
- Authentication and application status messages use status roles.
- Keyboard focus indicators are visible for buttons and form controls.
- Project status is shown as readable text as well as a visual style; status is not communicated by color alone.

## Manual release checks
Run these checks against the deployed version before calling the interface accessibility-reviewed:

1. Complete login, first-admin setup, project creation, task updates, approvals, and logout using only the keyboard.
2. Confirm focus is visible and follows a logical order; dialogs receive focus when opened and return focus when closed.
3. Use a screen reader to confirm headings, table headers, form labels, status updates, task names, and action names are announced clearly.
4. Zoom to 200% and test narrow mobile widths; confirm content and controls remain usable without overlapping.
5. Check text, focus, and status contrast against the actual rendered colors.
6. Confirm errors are announced and are not communicated by color alone.
7. Repeat the checks for admin, project manager, contributor, and viewer permissions.

Record the browser, assistive technology, date, tester, issues, and remediation before marking the review complete.
