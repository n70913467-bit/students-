# Project Control Dashboard

A responsive dashboard for tracking projects, tasks, activity history, approvals, and development progress.

## Scope
- Track projects by Running, On Hold, Stopped, and Planned status.
- Record latest activity, next action, and approval/permission requirements.
- Add and edit projects; add tasks, mark them complete/reopen, and remove tasks.
- View an activity history timeline for the selected project.
- Search and filter projects; export project summaries as CSV.
- Track the eight-stage development roadmap.

## Explicit exclusions
This project does **not** process, collect, or manage payments. No payment gateway, UPI, card, wallet, banking, merchant, or payment API integration is in scope.

## Run locally
Open `index.html` in a modern browser. Data is saved in that browser's localStorage. This is a single-user prototype; it is not yet a multi-user hosted application or server-backed database.

## Documentation
- [Workflow and acceptance criteria](docs/workflow.md)
- [Proposed database model and API outline](docs/data-model.md)

## Development status
- [x] Requirement planning
- [x] Workflow planning
- [x] Initial UI/UX design
- [x] Proposed relational data model
- [ ] Backend and authentication
- [ ] Complete frontend integration
- [ ] API integration (project-management APIs only, if required)
- [ ] Functional, security, and accessibility testing
- [ ] Deployment

## Data note
The initial sample projects are illustrative records based on project discussions, not live integrations or verified activity logs. The roadmap percentage reflects the project-build checklist, not production readiness.
