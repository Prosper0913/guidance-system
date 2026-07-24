# Guidance Appointment System — Architecture & Design

## 1. Overview

A standalone web-based system for managing guidance counseling appointments — both walk-in and online — with role-based access for Students, Guidance Counselors, and Administrators.

**Stack:** PHP 8 (OOP, PDO) · MySQL/MariaDB · Apache · Bootstrap 5 · Vanilla JS (AJAX/Fetch)
**Dev environment:** XAMPP (local) → LAMP-compatible host (deployment)

---

## 2. User Roles

| Role | Capabilities |
|---|---|
| **Student** | Register/login, book appointment (walk-in or online), view own appointment status/history, receive notifications, reschedule/cancel own pending requests |
| **Counselor** | Manage own availability/schedule, approve/decline/reschedule appointments, log session notes, mark attendance, flag/monitor special-needs students, view own reports |
| **Administrator** | Manage all user accounts, oversee all counselors' schedules, generate system-wide reports, configure concern categories, view audit logs |

Access control is enforced via a role field on `users` plus a middleware-style permission check on every protected page/endpoint.

---

## 3. Folder Structure (MVC-inspired, no framework)

```
guidance-system/
├── config/
│   ├── database.php          # PDO connection
│   └── constants.php         # roles, statuses, app config
├── public/                   # web root (only this is exposed by Apache)
│   ├── index.php             # front controller / router (optional)
│   ├── assets/ (css, js, img)
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── student/
│   │   ├── dashboard.php
│   │   ├── book-appointment.php
│   │   ├── my-appointments.php
│   │   └── notifications.php
│   ├── counselor/
│   │   ├── dashboard.php
│   │   ├── availability.php
│   │   ├── appointments.php   # approve/decline/reschedule
│   │   ├── session-notes.php
│   │   └── special-needs.php
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── manage-users.php
│   │   ├── manage-counselors.php
│   │   ├── reports.php
│   │   └── audit-logs.php
│   └── api/                  # AJAX endpoints, return JSON
│       ├── check-availability.php
│       ├── book.php
│       ├── update-status.php
│       └── get-notifications.php
├── src/
│   ├── Models/                # Appointment.php, User.php, Counselor.php, Notification.php, Report.php
│   ├── Controllers/           # thin controllers calling Models
│   ├── Services/
│   │   ├── SchedulingService.php   # conflict/double-booking checks
│   │   ├── NotificationService.php
│   │   └── ReportService.php
│   ├── Middleware/
│   │   └── AuthMiddleware.php # session + role check
│   └── Helpers/
│       ├── Validator.php
│       └── Csrf.php
├── database/
│   └── schema.sql
└── logs/
```

---

## 4. Core Modules (mapped to your objectives)

### 4.1 Account & Access Module (Obj. 2)
- Registration (Student self-registers with school ID; Counselor/Admin accounts created by Admin only)
- `password_hash()` / `password_verify()`, PHP sessions with regenerated session IDs on login
- CSRF tokens on all state-changing forms
- Role-based route guards (`AuthMiddleware`)
- Account status (active/disabled) so Admin can deactivate without deleting records

### 4.2 Appointment Booking Module (Obj. 1, 6)
- Two entry paths, same underlying table: `type = 'walk-in'` (logged by front-desk staff/counselor on the spot) or `type = 'online'` (student self-books)
- Concern category selected from a dropdown (`concern_categories`) — no need to publicly state the reason, satisfying the "discreet" objective
- Optional "urgent/confidential" flag that hides the concern detail from anyone except the assigned counselor

### 4.3 Scheduling Engine (Obj. 3)
- Counselors define recurring weekly `counselor_availability` (day + time blocks) plus one-off `counselor_availability_exceptions` (leave, holidays)
- Booking flow:
  1. Student picks counselor + date → AJAX call to `api/check-availability.php` returns open slots (availability minus existing approved/pending appointments)
  2. On submit, `SchedulingService` re-validates inside a DB transaction using `SELECT ... FOR UPDATE` on the slot to prevent race-condition double bookings
  3. Unique constraint at DB level (`counselor_id + appointment_date + appointment_time`) as a hard backstop

### 4.4 Notification Module (Obj. 4)
- In-app notifications table, polled by `api/get-notifications.php` (badge counter) — no websocket needed
- Triggered on: request received, approved, declined, rescheduled, cancelled, 24-hr reminder
- Optional email channel via PHPMailer/SMTP (school Google Workspace SMTP if available) — designed as a pluggable `NotificationService` so email can be added later without touching business logic
- Reminder dispatch handled by a scheduled task (Windows Task Scheduler locally / cron on the live server) hitting a `cron/send-reminders.php` script

### 4.5 Reports Module (Obj. 5)
- Pre-built report queries exposed to Counselor (own data) and Admin (system-wide):
  - Appointments by status/date range
  - Concern-category distribution
  - Attendance/no-show rate
  - Busiest days/time slots
  - Per-counselor caseload
  - Special-needs monitoring summary
- Exportable to CSV/PDF from the admin panel

### 4.6 Special Needs Monitoring Module (Obj. 8)
- `special_needs_monitoring` table linked to student profile: condition/accommodation notes, assigned counselor, monitoring frequency, next check-in date
- Dashboard widget for counselors flags students due for a check-in
- Access restricted — this data is only visible to the assigned counselor and Admin, never shown in general lists

### 4.7 Efficiency / Paperless Objective (Obj. 7)
- All the above replaces paper log sheets: `attendance_logs`, `appointment status history`, and `session_notes` form the digital audit trail administrators previously kept manually.

---

## 5. Appointment Status Flow

```
pending → approved → completed
   │           │
   │           └──> no-show
   │
   ├──> declined
   ├──> cancelled (by student or counselor)
   └──> rescheduled ──> pending (new date/time, logged in appointment_logs)
```

Every transition is written to `appointment_logs` (old status, new status, changed_by, timestamp, optional remarks) — this is what feeds both notifications and reports.

---

## 6. Security Notes
- All queries via PDO prepared statements (no raw SQL concatenation)
- Session-based auth; sensitive pages check `role` server-side, never trust client-side hiding
- Session notes and special-needs data are access-restricted at the query level, not just the UI level
- Audit log table records who viewed/changed sensitive records (esp. for Admin oversight)

---

## 7. Next Steps
1. Review/adjust schema (`database/schema.sql`, attached separately)
2. Scaffold `config/`, `AuthMiddleware`, and login/register flow first (foundation for everything else)
3. Build booking + scheduling engine (core value proposition)
4. Layer in notifications → reports → special-needs monitoring
