# Guidance Appointment System — Setup Guide

## 1. Requirements
- XAMPP (Apache + MySQL + PHP 8+)

## 2. Installation
1. Copy the `guidance-system` folder into your XAMPP `htdocs` directory.
   Result should be: `C:\xampp\htdocs\guidance-system\`
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open phpMyAdmin (http://localhost/phpmyadmin), and import `database/schema.sql`.
   This creates the `guidance_appointment_system` database with all tables and seed concern categories.
4. Check `config/database.php` — default XAMPP credentials (`root` / no password) are already set.
   Update `config/constants.php` → `BASE_URL` if your folder name/path differs.
5. Visit: http://localhost/guidance-system/public/register.php to create a test student account.
6. To create the first Counselor/Admin account, insert one manually via phpMyAdmin (or temporarily
   allow `admin` role in register.php), since staff accounts are normally created by an Admin
   from Manage Users.

### Creating the first Admin account manually (one-time)
Run this in phpMyAdmin's SQL tab (change the values first):
```sql
INSERT INTO users (role, id_number, first_name, last_name, email, password_hash)
VALUES ('admin', 'ADMIN-001', 'Guidance', 'Admin', 'admin@tcm.edu.ph',
        '$2y$10$replace_with_a_real_password_hash');
```
Generate a bcrypt hash with this PHP snippet (run once, e.g. via `php -a` or a scratch file):
```php
echo password_hash('yourpassword', PASSWORD_DEFAULT);
```
Once logged in as Admin, use **Manage Users** to create Counselor accounts through the UI.

## 3. Folder Structure
See `ARCHITECTURE.md` for the full breakdown of modules and design decisions.

## 4. What's implemented
- Student self-registration, login/logout, role-based access control (CSRF-protected)
- Online appointment booking with real-time slot availability (AJAX) + double-booking
  prevention via DB transaction + row locking + unique constraint
- Walk-in appointments: logged directly by counselor (extend `appointments.php` insert
  flow, or add a quick "Log Walk-in" form using the same `SchedulingService::book()`
  with `type = 'walk-in'`)
- Counselor: manage weekly availability + date-specific blocks, approve/decline/complete/
  reschedule/no-show appointments, confidential session notes, special-needs monitoring list
- In-app notifications on every status change (approved/declined/cancelled/completed/no-show)
- Admin: create/disable Counselor & Admin accounts, view all appointments, audit log of every
  status change, reports (status summary, concern categories, attendance, caseload, busiest
  slots, special-needs breakdown)

## 5. Google Calendar Integration (two-way sync)

Each counselor can connect their own Google Calendar. Once connected:
- Their existing Google Calendar events block those times from student booking (pulled via the Calendar `freeBusy` API)
- Approved appointments are automatically created as events on their Google Calendar
- Declined/cancelled/no-show appointments remove the synced event

### One-time Google Cloud setup (you'll need to do this yourself)
1. Go to [Google Cloud Console](https://console.cloud.google.com/) → create a project (or use an existing one).
2. **APIs & Services → Library** → enable **Google Calendar API**.
3. **APIs & Services → OAuth consent screen** → set it up (External is fine for testing — while in "Testing" mode, add each counselor's Google account under Test Users, or they won't be able to complete the consent screen).
4. **APIs & Services → Credentials → Create Credentials → OAuth client ID**
   - Application type: **Web application**
   - Authorized redirect URI: must exactly match what you put in `config/google.php`'s `GOOGLE_REDIRECT_URI` — by default this is
     `http://localhost{BASE_URL}/counselor/google-callback.php` (so update it to match your actual host/path once deployed, e.g. `https://yourschool.edu.ph/guidance/public/counselor/google-callback.php`)
5. Copy the **Client ID** and **Client Secret** into `config/google.php`.

### Database migration
Run `database/migration_google_calendar.sql` in phpMyAdmin against your existing database — it adds the token storage table and a `google_event_id` column on `appointments`.

### Usage
Counselor logs in → **Availability** page → **Connect Google Calendar** → completes Google's consent screen → done. Disconnecting is available on the same page.

### Notes / limitations
- All appointment slots are currently assumed to be 30 minutes for the calendar event duration; adjust `GoogleSyncService::buildEventPayload()` if you want this to follow the counselor's configured `slot_minutes` instead.
- If Google's API is unreachable or a counselor hasn't connected, the system fails open — booking still works using only the internal schedule, it just won't reflect their Google Calendar busy times.
- `Appointment::reschedule()` exists in the model but isn't wired to a UI page yet; if you build a reschedule flow, call `GoogleSyncService::pushUpdate()` after a successful reschedule to keep the synced event in sync too.
- Refresh tokens are stored in plain text in `counselor_google_tokens` for simplicity — fine for a school capstone/local deployment, but consider encrypting them at rest before a wider production rollout.

## 7. Multiple Pending Requests Per Slot

Only an **approved** appointment locks a time slot. Multiple students can be pending for the same slot at once (booking no longer blocks this) — the counselor decides who gets it.

- **Run `database/migration_pending_conflicts.sql`** against your existing database — it drops the old hard uniqueness constraint that would otherwise reject a second pending request for the same slot.
- When a counselor **approves** one request, the system automatically **declines every other pending request for that exact same slot** and sends each of those students a notification explaining the time was taken by another student.
- The counselor's pending list shows a **⚠ +N other requests for this slot** badge so they know a conflict exists before deciding.
- Counselors can also send a **free-form message** to any pending student (e.g. "Can you come 15 minutes earlier instead?") via the **Message** button — this doesn't change the appointment's status, it's just a notification.

## 9. Guidance Office Student Referral Form

Digitizes The College of Maasin's paper referral form (teacher/staff refers a student to Guidance).

- **Run `database/migration_referrals.sql`** against your existing database first — creates the `referrals` table and adds a `referral_id` column to `notifications`.
- **`public/referral-form.php`** is a **public page — no login required**. This is intentional: referring parties (teachers, staff) usually don't have system accounts, same as they wouldn't have one to fill out the paper form. It mirrors Sections I–VII of the paper form exactly (student info, referring party info, concern checkboxes by category, incident description, actions taken prior, urgency assessment, consent).
- On submit, every active Counselor and Admin gets an in-app notification (marked 🔴 if flagged urgent) so triage doesn't depend on one person checking a page.
- **`public/counselor/referrals.php`** and **`referral-view.php`** (Counselor + Admin) — this is where Section VIII ("Guidance Office Use Only") happens: accept/for-clarification/referred-back status, initial action checklist, assigning a guidance advocate, and remarks.
- **Linking to a student account**: the referral stores whatever the referring party typed (name, ID number, etc.) as free text, since they're often filling this out from memory, not from your system's user list. Guidance staff search and link it to an actual registered student account from the referral detail page before an appointment can be scheduled from it.
- **Convert to appointment**: once a referral is linked to a student account and assigned to a counselor, a "Schedule Appointment" panel appears on the referral detail page — this creates an already-*approved* appointment (not a pending request, since Guidance is actively scheduling it) and syncs to the counselor's Google Calendar if connected.
- Referrals flagged with risk of self-harm/harm to others/severe distress/crisis show a red warning banner at the top of the referral detail view so they can't be missed during triage.

### Design simplifications from the paper form
- The paper form has a top-level "Department:" field and a separate "Department/Office" field under Referring Party — these were merged into one `department` field on the digital form to avoid asking the same thing twice.
- "Signature of Referring Party" is captured as the typed name already required in Section II, plus the required consent checkbox — there's no separate signature capture, since this is a web form rather than a printed one.
- The form does **not** currently block online appointment booking without a prior referral, even though the paper form states "No Referral, No Appointment, except in emergency cases." That's a policy decision (e.g. should walk-ins be exempt too, should it apply to first-time students only) that's easy to wire in once decided — happy to add it as a hard requirement if you want it enforced in the booking flow.

## 10. Suggested next additions

## 11. Booking Now Goes Through Referral Intake (not direct self-booking)

Per the school's actual policy stated on the paper form ("No Referral, No Appointment, except in emergency cases"), **`student/book-appointment.php` no longer lets students pick a counselor/date/time directly.** It's now the same referral intake used for teacher/staff referrals, tailored for a logged-in student:

- No "Referring Party" section — the student *is* the referring party, filled in automatically
- No live counselor/slot picker — instead, the student can optionally note a **preferred method (online/walk-in), preferred counselor, and preferred date** as soft hints
- Since the student is authenticated, `student_id` is linked immediately on submit (no manual linking step needed, unlike teacher-submitted referrals)
- The actual date/time is set later by Guidance staff in `counselor/referral-view.php`, which still uses the real-time availability engine (`Availability`/`api/check-availability.php`) — it's just staff using it now, not the student directly
- The scheduling panel pre-selects the student's preferred counselor (if the referral isn't assigned yet) and pre-fills their preferred date, so staff aren't starting from scratch

**New pages:**
- `student/my-referrals.php` — lets students track the status of what they've submitted (pending/accepted/for clarification/referred back), since "My Appointments" stays empty until Guidance actually schedules something
- Student dashboard now also shows a "Requests Awaiting Review" count alongside upcoming appointments

**Removed:** `api/book.php` (the old self-booking AJAX endpoint) — dead code once direct booking was removed. `SchedulingService::book()` itself is untouched and still used if you build the walk-in-logging feature mentioned above; it just has no active caller right now.

**Migration:** run `database/migration_student_referral_booking.sql` — adds `preferred_type`, `preferred_counselor_id`, and `preferred_date` columns to `referrals`.

**Crisis resources on the form:** if a student checks "Thoughts of self-harm" or "This feels like a crisis situation," the form immediately shows walk-in guidance, the NCMH Crisis Hotline, and the 911 emergency line — this doesn't wait for staff to review the submission later.
- Email notifications (PHPMailer + SMTP) — `NotificationService` is already structured so this
  slots in without touching booking/scheduling logic
- A dedicated "Log Walk-in" form for front-desk/counselor use (reuses `SchedulingService::book()`)
- Scheduled reminder script (`cron/send-reminders.php`) run via Windows Task Scheduler or cron,
  calling `NotificationService::reminder()` for appointments happening within 24 hours
- CSV/PDF export buttons on the Reports page
- ESP32/AS608 biometric attendance integration, if this system is later merged with your CMS's
  attendance component
-------------------------------------------
REMOVE PREFFERED METHOD IN REFERRAL FORM