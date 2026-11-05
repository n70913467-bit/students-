# Data Model and Persistence Plan

## Design goals
Keep project records, tasks, activity history, and approval requests separate so the dashboard can grow from a local prototype into a multi-user application.

## Proposed relational tables

### users
- `id` UUID primary key
- `name` varchar(120) not null
- `email` varchar(255) unique not null
- `password_hash` text not null (never store plaintext passwords)
- `role` enum: admin, member, viewer
- `is_active` boolean default true
- `created_at`, `updated_at` timestamps

### projects
- `id` UUID primary key
- `name` varchar(160) not null
- `description` text nullable
- `status` enum: planned, running, on_hold, stopped
- `owner_id` UUID references users(id)
- `next_action` text nullable
- `created_at`, `updated_at` timestamps
- `archived_at` timestamp nullable

### project_members
- `project_id` UUID references projects(id)
- `user_id` UUID references users(id)
- `member_role` enum: manager, contributor, viewer
- `joined_at` timestamp
- Primary key: (project_id, user_id)

### tasks
- `id` UUID primary key
- `project_id` UUID references projects(id)
- `title` varchar(200) not null
- `description` text nullable
- `status` enum: todo, in_progress, blocked, done
- `priority` enum: low, medium, high, urgent
- `assignee_id` UUID references users(id), nullable
- `due_date` date nullable
- `created_at`, `updated_at`, `completed_at` timestamps

### activity_logs
- `id` UUID primary key
- `project_id` UUID references projects(id)
- `task_id` UUID references tasks(id), nullable
- `actor_id` UUID references users(id), nullable for system events
- `event_type` varchar(60) not null
- `message` text not null
- `created_at` timestamp
- Indexes: (project_id, created_at), (task_id, created_at)

### approval_requests
- `id` UUID primary key
- `project_id` UUID references projects(id)
- `requested_by` UUID references users(id)
- `approver_id` UUID references users(id), nullable
- `title` varchar(160) not null
- `details` text nullable
- `status` enum: pending, approved, rejected, cancelled
- `requested_at`, `decided_at` timestamps nullable

## Relationships
- One project has many tasks, activity logs, and approval requests.
- Users can join many projects through project_members.
- A task may have one assignee.
- Activity logs belong to a project and optionally a task and actor.
- An approval request belongs to one project and records requester and optional approver.

## API outline (future backend)
- `GET /api/projects?status=&q=`
- `POST /api/projects`
- `GET /api/projects/:id`
- `PATCH /api/projects/:id`
- `GET /api/projects/:id/tasks`
- `POST /api/projects/:id/tasks`
- `PATCH /api/tasks/:id`
- `POST /api/projects/:id/activities`
- `GET /api/projects/:id/activities`
- `POST /api/projects/:id/approvals`
- `PATCH /api/approvals/:id`

## Data integrity and security
- Use foreign keys and transactions for related changes.
- Validate enum values and required fields on the server.
- Hash passwords using a modern password-hashing algorithm; never store secrets in source code.
- Enforce project membership and role permissions on every protected endpoint.
- Record important changes in activity_logs.
- Use pagination for project/task lists and indexes for common filters.
- Back up the database and define retention/deletion rules before production.

## Current versus target
Current prototype: one browser's localStorage; no shared database, authentication, or API.
Target: PostgreSQL (or another relational database) behind an authenticated backend API. The schema above is a proposed design, not a deployed database.
