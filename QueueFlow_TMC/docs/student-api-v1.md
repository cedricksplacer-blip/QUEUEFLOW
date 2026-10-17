# Student Mobile API — v1

The contract the QueueFlow TMC mobile app talks to. Base path `/api/v1`,
stateless, authenticated with a Sanctum bearer token.

- **Scope:** every role can authenticate. A student uses the app; staff and admin
  use the JSON endpoints under `/api/v1/student`, `/api/v1/staff` and
  `/api/v1/admin`. What a role may then *do* is decided per route, never at
  sign-in — see §7.
- **Source of truth:** `routes/api.php` (routing),
  `app/Support/ApiContract.php` (published endpoint list),
  `app/Support/ApiPresenter.php` (payload shapes).
- **Tests:** `tests/Feature/Api/ApiIntegrationTest.php`.

Every payload below was captured from the running application over the LAN, not
written by hand. If a shape changes, that test file and this document change
together.

---

## 0. Running the server

The app talks to this machine over the campus WiFi, so Laravel has to be started
on all interfaces, not on loopback:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

`.env` carries `APP_URL=http://192.168.1.154:8000` (this PC's current LAN
lease — update it when the lease changes), and the URLs published by
`GET /api/v1` are built from it — which is why discovery is also a quick check
that the server is reachable and configured: if it answers with `127.0.0.1` in
those URLs, the app has been handed the wrong host.

`APP_URL` is only the advertised base. MySQL stays on loopback:

```
Flutter  →  192.168.1.154:8000  →  Laravel  →  127.0.0.1:3306  →  MySQL
```

Never point `DB_HOST` at the LAN address; MySQL is on this machine.

After editing `.env`:

```bash
php artisan config:clear
php artisan cache:clear
```

A native Android build is not subject to CORS, so nothing extra is needed there.
A Flutter **web** build is: `config/cors.php` allows loopback and the RFC1918
private ranges by pattern, because the Flutter tool picks its own port per run.
Credentials are not supported — every call carries `Authorization: Bearer` and no
cookie — which is also why `sanctum.stateful` is empty.

---

## 1. Connecting

Point the app at `http://<host>/api/v1`. On launch, before doing anything else:

```http
GET /api/v1
```

```json
{
  "api": "queueflow-tmc",
  "version": "v1",
  "status": "ok",
  "time": "2026-10-03T19:06:54+08:00",
  "auth": "bearer",
  "header": "Authorization: Bearer <token>",
  "note": "Passwords in this schema are stored as clear text. Do not log or share a token; sign out revokes only the current one.",
  "endpoints": {
    "login": ["http://192.168.1.154:8000/api/v1/auth/login", "http://192.168.1.154:8000/api/login"],
    "offices": ["http://192.168.1.154:8000/api/v1/offices", "…"],
    "…": "…"
  }
}
```

`auth` is the string `"bearer"` and the header is named separately in `header`.
`endpoints` is a flat map, and the values are arrays because most endpoints are
published under both a versioned and a short alias pointing at the same handler.

This call needs no token and returns no account data. Use it to confirm the app
is pointed at a v1 server, and read `endpoints` rather than hardcoding paths.

### Version header

Every response — including 401s, 422s and 500s — carries:

```
X-Api-Version: v1
```

Check it whenever a response looks unfamiliar. If a build ever receives
`X-Api-Version: v2` it is talking to a newer contract, and the app should stop
and prompt for an update rather than guess at field names.

---

## 2. Authentication

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "identifier": "24-019083", "password": "252005", "device_name": "pixel-8" }
```

```json
{
  "message": "Login successful.",
  "user": {
    "id": 98,
    "name": "Cedrick Placer",
    "email": "cedricksimacon@gmail.com",
    "role": "student",
    "student_id": "24-019083",
    "course": "BSIT",
    "block": "A1",
    "year_level": "3rd Year",
    "phone": null,
    "office_id": null,
    "is_active": true,
    "created_at": "2026-10-03T19:06:54+08:00",
    "updated_at": "2026-10-03T19:06:54+08:00"
  },
  "token": "1|s0mE…",
  "token_type": "Bearer",
  "abilities": ["tickets:own", "profile:write"]
}
```

Send the token on every other call:

```http
Authorization: Bearer 1|s0mE…
```

### What goes in `identifier`

One field carries whichever identifier the account is known by:

| Role | Send | Example |
|---|---|---|
| student | Student ID | `24-019083` |
| staff | email | `registrarbsit@gmail.com` |
| admin | email | `cedricksplacer@gmail.com` |

The value is resolved against both `student_id` and `email`, so a student who has
an address on file can sign in with either — the same resolution the browser
portal uses, so an account cannot be reachable through one door and invisible
through the other.

For an older build's sake, `email` and `student_id` are still accepted *as the
field name* and fold into the same lookup, and into the same rate-limit bucket.
Sending one of those instead of `identifier` is therefore not a way to buy extra
sign-in attempts.

Notes for the client:

- `device_name` is optional (default `QueueFlow Flutter`). Reusing a name revokes
  that device's previous token, so a re-login does not pile up tokens and the
  phone stays signed in while a shared lab PC is signed out.
- The token is the app's only credential. Store it in platform secure storage
  (Keychain / Keystore), **not** shared preferences.
- The account object is called **`user`**, not `student`, and is the same for
  every role — read `user.role` to decide what to render. It never contains a
  password. Passwords in this schema are stored in clear text, so no endpoint may
  echo the user row — don't add one that does.
- A wrong password **and** an identifier nobody holds both return `401` with the
  identical message `Those credentials do not match our records.`, so the
  endpoint cannot be used to discover which Student IDs are registered.
- A **deactivated** account gets `403`, not `401`: the password was right, and
  the app should stop retrying and tell the student to contact the registrar.
- A missing `identifier` (or `email` / `student_id`) is `422` with
  `errors.identifier`.

`POST /api/v1/auth/logout` revokes the calling token only — signing out on one
device leaves the others signed in.

---

## 3. Rate limits

| Bucket | Limit | Keyed on | Applies to |
|---|---|---|---|
| `student-login` | 5 / min | identifier + IP | sign-in |
| `student-reset` | 10 / min | identifier + IP | forgot/reset password |
| `student-api` | 120 / min | token id (IP if signed out) | everything else |

A `429` always includes `Retry-After`. Honour it rather than retrying
immediately — the live-board poll in particular should back off, since campus
WiFi is usually the bottleneck rather than the server.

Password recovery additionally enforces a **60-second per-address cool-down**,
so asking twice in a row returns `200` with a `retry_after` number in seconds
and does not send a second email. A new code also voids any earlier unused one,
so only the most recent code can be redeemed.

---

## 4. Errors

| Status | Meaning | Client action |
|---|---|---|
| `401` | Missing / expired / revoked token | Clear the token, send to sign-in |
| `403` | Token valid but wrong role | Sign out; this account is not a student |
| `404` | Not found **or** not yours | Show "not found" — see below |
| `422` | Validation failed | Read `errors` |
| `429` | Rate limited | Wait for `Retry-After` |
| `503` | Reset code could not be sent | Retry shortly |

Validation errors:

```json
{
  "message": "The given data was invalid.",
  "errors": { "course_id": ["Your selected course does not match your student profile."] }
}
```

**404 is deliberate for other people's data.** Asking for another student's
ticket returns `404`, not `403`, so the API never confirms that the row exists.
Treat `404` as "gone or not yours" and do not distinguish the two in the UI.

---

## 5. Endpoints

### Account

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/v1/auth/me` | current account, any role |
| `PATCH` | `/api/v1/auth/me` | `name`, `email`, `phone`, `avatar`, `remove_avatar` |
| `POST` | `/api/v1/auth/password` | `current_password`, `password`, `password_confirmation` |
| `POST` | `/api/v1/auth/forgot-password` | `email` |
| `POST` | `/api/v1/auth/reset-password` | `email`, `code`, `password`, `password_confirmation` |

Only `name`, `email` and `phone` are settable, plus the avatar. **`student_id`,
`course`, `block`, `year_level`, `role`, `office_id` and `is_active` are not
accepted here** — they are ignored rather than rejected, so don't send them
hoping to change them. The registrar queue is course-specific, and Student ID is
the account's identity, so only an administrator can move those. Changing `email`
clears its verified flag, so the address has to be verified again.

`avatar` is a `multipart/form-data` image, max 2 MB; `remove_avatar: true` clears
it. Uploading a new one deletes the file it replaced.

Changing a password revokes every other device token. `reset-password` revokes
all of them, including the current one — the account must sign in again.

The reset flow mails a 6-digit code (valid 10 minutes) rather than a link,
because the app has no browser to redirect into. The response is deliberately
identical whether or not the address is a registered student, so the endpoint
cannot be used to discover which addresses hold accounts.

### One round trip

| Method | Path |
|---|---|
| `GET` | `/api/v1/bootstrap` |

Returns `student`, `settings`, `announcements`, `courses` and `offices`
together. Use this on cold start and on resume rather than five separate calls.

### Catalogue

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/v1/offices` | filtered to the student's course |
| `GET` | `/api/v1/offices/{office}` | `404` if it belongs to another course |
| `GET` | `/api/v1/offices/{office}/live` | poll target |
| `GET` | `/api/v1/courses` | active courses |
| `GET` | `/api/v1/announcements` | published only |
| `GET` | `/api/v1/settings` | public settings |

An office, abbreviated (the `services[].station` block is repeated per service
and is omitted here):

```json
{
  "offices": [
    {
      "id": 3,
      "name": "Admissions",
      "code": "ADM",
      "description": "Admission inquiries, applications, assessment…",
      "is_active": true,
      "status": "online",
      "opening_time": "08:00",
      "closing_time": "15:00",
      "max_slots": 100,
      "course": null,
      "course_code": null,
      "window_open": false,
      "open_batch": {
        "id": 91, "batch_no": 1, "status": "open", "max_slots": 100,
        "submitted_count": 1, "served_count": 0,
        "opened_at": "2026-09-27T14:39:26+08:00",
        "closed_at": null, "completed_at": null
      },
      "active_batch": { "id": 91, "…": "same shape" },
      "accepting": false,
      "waiting_count": 0,
      "serving_count": 0,
      "services": [
        {
          "id": 144,
          "office_id": 3,
          "station_id": 21,
          "name": "Admission Inquiry",
          "description": "Ask about admission requirements, schedules, and procedures.",
          "requires_course": false,
          "is_active": true,
          "station": {
            "id": 21,
            "office_id": 3,
            "name": "Admissions Main Station",
            "location": "Trinidad Municipal College, President Carlos P. Garcia St., Santo Nino, Trinidad, Bohol",
            "course_codes": ["BSIT", "BSOA", "BSCRIM", "BEED", "BSED", "BACOMM", "BAPOLSCI"],
            "min_daily_students": 40,
            "target_daily_students": 60,
            "max_daily_slots": 100,
            "opening_time": "08:00",
            "closing_time": "15:00",
            "is_active": true,
            "accepts_course": true
          }
        }
      ],
      "stations": [ { "…": "same shape as services[].station" } ]
    }
  ]
}
```

**Use `accepting` to decide whether to offer "Get a ticket".** It is the single
field that already combines: the office is active, the staff have switched it
online, a batch is open, the batch is inside its operating window, and the
batch has not been deployed. Computing it from `open_batch` + `window_open` in
the app will drift from the server.

`status` is the daily online/offline switch, `online` or `offline`. It is
separate from `is_active` (which means "still part of the campus"): an office
can be inactive and stay that way, while an active office's staff flip it
offline whenever the counter stops accepting numbers. An offline office still
ships in the catalogue - the app is expected to show it, locked, so the
student knows the counter exists and why they cannot take a number - and
`accepting` is `false` the moment it goes offline.

Registrar offices (`REG-*`) are per-course: a student only ever sees the
registrar for the course on their profile, and asking for another one directly
returns `404`. Offices that are not course-specific are visible to everyone.

### Registrar course queues

`GET /api/v1/registrar/courses`

The single Registrar window's seven course queues, aggregated. One row per
college, shaped like the office payload minus the station block:

| Field | Notes |
|---|---|
| `course` / `course_code` | the college this queue serves |
| `office_id` / `office_name` | the `REG-*` office behind the queue |
| `status` | the office's own switch — `offline` when the Registrar is switched off |
| `queue_status`, `accepting`, `total_slots`, `available_slots`, `used_slots` | as for an office; being offline folds into all of them |
| `is_enrolled_course` | whether this course row is the student's own |
| `services` | the queue's services, so the picker never needs a second call |

`totals` sums `total_slots` and `available_slots` across every course; that
aggregate is what the single Registrar card shows. An office switched offline
stamps every one of its rows `status: offline`, so the app can lock the whole
picker rather than report them as merely "full".

### Live board

`GET /api/v1/offices/{office}/live`

```json
{
  "office": { "id": 3, "name": "Admissions", "code": "ADM" },
  "now_serving": { "queue_number": "ADM-014", "called_at": "2026-09-27T15:02:11+08:00" },
  "waiting_count": 12,
  "serving_count": 2,
  "served_count": 37,
  "recent_served": [
    { "queue_number": "ADM-013", "served_at": "2026-09-27T15:01:44+08:00" }
  ],
  "window_open": true
}
```

`now_serving` and the `recent_served` entries are `null` / empty when there is
nothing to show. All counters are for **today only**.

### Tickets

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/v1/tickets` | `?status=active\|history\|all\|<status>`, `?limit=` (1–200, default 100) |
| `POST` | `/api/v1/tickets` | take a ticket |
| `GET` | `/api/v1/tickets/{ticket}` | detail + live position |
| `POST` | `/api/v1/tickets/{ticket}/cancel` | waiting tickets only |

`GET /api/v1/tickets` also returns `counts`, so the app dashboard needs no extra
call:

```json
{
  "tickets": [ { "…": "see below" } ],
  "counts": {
    "total": 12, "waiting": 1, "serving": 0,
    "served": 9, "cancelled": 2, "today": 1
  }
}
```

A ticket:

```json
{
  "id": 91,
  "queue_number": "ADM-001",
  "status": "waiting",
  "priority": "regular",
  "is_pwd": false,
  "is_waiting": true,
  "is_serving": false,
  "is_served": false,
  "is_cancelled": false,
  "purpose": "Enrolment verification",
  "year_level": "3rd Year",
  "has_evidence": false,
  "office": { "id": 3, "name": "Admissions", "code": "ADM" },
  "service": { "id": 144, "name": "Admission Inquiry" },
  "station": { "id": 21, "name": "Admissions Main Station", "location": "…" },
  "course": {
    "id": 8, "code": "BSIT",
    "name": "Bachelor of Science in Information Technology",
    "office_id": 63, "is_active": true
  },
  "deployment": { "id": 91, "batch_no": 1, "status": "open", "…": "as above" },
  "served_by": null,
  "called_at": null,
  "call_duration_minutes": 5,
  "expires_at": null,
  "remaining_seconds": 0,
  "is_expired": false,
  "arrived_at": null,
  "is_arrived": false,
  "cancelled_at": null,
  "cancel_reason": null,
  "served_at": null,
  "created_at": "2026-09-27T14:39:26+08:00",
  "updated_at": "2026-09-27T14:39:26+08:00"
}
```

`status` is one of `waiting`, `serving`, `served`, `cancelled`. The `is_*`
booleans are provided so the client does not have to switch on the string.

A called ticket carries the server-stamped countdown: `called_at` when the
call started, `call_duration_minutes` (this office's saved Queue Timeout
Duration unless overridden per call, default 5), `expires_at` the deadline,
`remaining_seconds`/`is_expired` computed server-side. `arrived_at`/
`is_arrived` mark a Stop-Timer confirmation (deadline cleared, still
`serving` and still completable; never expires afterwards).
`cancelled_at`/`cancel_reason` record a void with its staff note.

`GET /api/v1/tickets/{ticket}` adds a `queue` block:

```json
{
  "ticket": { "…": "as above" },
  "queue": {
    "estimated_wait_minutes": 20,
    "people_ahead": 4,
    "position": 5,
    "minutes_per_ticket": 5,
    "now_serving": { "queue_number": "ADM-014", "called_at": "…" },
    "waiting_count": 12
  }
}
```

`position` counts PWD tickets ahead as ahead, matching the order the office will
actually serve in. It is `0` once the ticket is no longer waiting.

**Taking a ticket** — `POST /api/v1/tickets`:

| Field | Required | Notes |
|---|---|---|
| `office_id` | yes | |
| `service_id` | yes | must belong to `office_id` |
| `course_id` | yes | must match the student's profile course |
| `priority` | yes | `regular` or `pwd` |
| `purpose` | no | free text, max 255 |
| `year_level` | no | falls back to the profile value |
| `priority_evidence` | no | `multipart/form-data` file; required in practice for `pwd` |

Returns `201` with `message`, `ticket` and `queue`.

A ticket can only be taken while a batch is `open`, the office is `online` and
inside its operating window, subject to the per-student daily cap
(`max_active_tickets_per_student`, default 1). All of these produce `422` with
a human-readable `message` — show `message` to the student, and use
`errors.<field>` only to highlight a field.

**Cancelling** is refused for anything not `waiting` (a ticket already being
served cannot be withdrawn). That returns `422` with `errors.status`.

---

### Staff and admin

Reachable only by a token of that role. `403` otherwise.

| Method | Path | Role | Notes |
|---|---|---|---|
| `GET` | `/api/v1/staff/bootstrap` | staff | `staff`, `settings`, `announcements`, and `offices` narrowed to the one assigned office |
| `GET` | `/api/v1/admin/overview` | admin | `counts`, every office, and the campus totals |
| `GET` | `/api/v1/queues` | staff, admin | the queues the caller may see |
| `GET` | `/api/v1/queues/{office}` | staff, admin | `now_serving`, `waiting`, `recently_served`, `batch` and the counters |
| `POST` | `/api/v1/queues/{office}/call-next` | staff, admin | calls the next ticket; PWD first. Optional body `call_duration_minutes` (1–60); omitted means this office's saved Queue Timeout Duration (default 5) |
| `GET` | `/api/v1/queues/{office}/timeout` | staff, admin | this office's saved Queue Timeout Duration (`call_timeout_minutes`, default 5) |
| `PUT` | `/api/v1/queues/{office}/timeout` | staff, admin | save the timeout; body `call_duration_minutes` (1–60, required) |
| `POST` | `/api/v1/queues/{office}/tickets/{ticket}/arrive` | staff, admin | Stop Timer: records arrival, clears the deadline, keeps `serving`; idempotent; refused once expired |
| `POST` | `/api/v1/queues/{office}/tickets/{ticket}/recall` | staff, admin | Call Again: same serving ticket, fresh `called_at`/`expires_at` from `call_duration_minutes` (1–60, else office timeout); keeps serving position; refused after arrival; row-locked |
| `POST` | `/api/v1/queues/{office}/tickets/{ticket}/cancel` | staff, admin | voids a `serving`/`waiting` ticket; optional body `cancel_reason` (max 255), logged with staff/office/ticket/timestamp |
| `POST` | `/api/v1/queues/{office}/tickets/{ticket}/serve` | staff, admin | finishes the ticket at the window; refused when expired (use Call Again / No-Show) |
| `POST` | `/api/v1/queues/{office}/tickets/{ticket}/no-show` | staff, admin | terminal No-Show: `serving` → `no_show`, preserves `called_at`/`expires_at`, stamps `no_show_at`/`no_show_by`, frees window; refused after arrival; row-locked |
| `POST` | `/api/v1/queues/{office}/expire` | staff, admin | counts this office's overdue serving tickets awaiting Call Again / No-Show (non-destructive; the `queues:expire-overdue` schedule reports the same count every minute) |
| `PUT` | `/api/v1/queues/{office}/tickets/{ticket}` | admin | plain status write; cannot force `serving` |
| `POST` | `/api/v1/offices/{office}/deploy` | staff, admin | opens the batch for service |
| `POST` | `/api/v1/offices/{office}/close-batch` | staff, admin | stops accepting tickets |
| `POST` | `/api/v1/offices/{office}/complete-batch` | staff, admin | closes the batch out |
| `POST` | `/api/v1/offices/{office}/start-batch` | staff, admin | opens a fresh batch |
| `POST` | `/api/v1/offices/{office}/status` | staff, admin | body `status` ∈ `online`/`offline`; flips the office (and, for a Registrar office, the whole course group) for the day |
| `GET` | `/api/v1/accounts` | admin | list/filter/search accounts |
| `POST` | `/api/v1/accounts` | admin | create; `AccountRules::apply()` drops fields that do not apply to the role |
| `GET`/`PATCH`/`DELETE` | `/api/v1/accounts/{user}` | admin | the last admin cannot be deactivated, and neither can your own account |
| `POST` | `/api/v1/accounts/{user}/restore` | admin | reactivate |

A staff member with no active operational office still authenticates, but
`/api/v1/staff/bootstrap` answers `403` with a message saying so — that is an
assignment problem for an administrator to fix, not something the app should
render as an empty screen.

These mirror the Blade consoles and run the same `QueueDeploymentManager` and
`QueueCounter`, so the web counter and the API cannot disagree about who is
being served or in what order.

---

## 6. Versioning policy

`/api/v1` is frozen. Adding a field, or a new endpoint, is backward compatible
and can ship in place. Anything that removes a field, renames one, or changes a
meaning ships as `/api/v2`, and `/api/v1` keeps answering for as long as phones
in the field need it.

`X-Api-Version` is the client's check for this: a build that sees `v2` is talking
to a newer contract and should stop and prompt for an update rather than guess at
field names.

`tests/Feature/Api/ApiIntegrationTest.php` is the executable half of this
document — 64 tests over a real MySQL database, covering that a token
authenticates and a revoked one does not, that each role is refused the other
roles' payloads, that a staff token cannot touch an office it is not assigned to,
that a student sees only their own tickets, and that the unversioned aliases
reach the same handlers as their versioned twins.

The unversioned `/api/student/*` password-recovery pair is what an already-deployed
app build calls. It is unchanged and still works; new builds should target
`/api/v1`.

---

## 7. What this API deliberately does not expose

- **Any password, ever.** `users.password` is clear text in this schema, so a
  blanket "dump the users table" endpoint would publish every account on campus.
  `ApiPresenter::user()` is an explicit whitelist and the column is never
  serialised.
- **Other students' data.** Every ticket read is checked against the signed-in
  user id; the office catalogue is course-scoped.
- **Another role's payloads.** A student token reaching `/api/v1/staff/bootstrap`
  or `/api/v1/admin/overview` is `403`, and a staff token reaching the admin
  overview is `403` too. Staff *can* sign in — they have their own console
  endpoints — but `role:admin,staff` alone is not the whole check: every
  queue-mutating endpoint also runs `guardOffice()`, so a staff token cannot act
  on an office it is not assigned to, whatever the route middleware says.
- **Direct MySQL access.** The app used to fall back to reading these tables
  over a direct connection. That fallback should be removed: it ships database
  credentials to the device, bypasses every rule above, and breaks the moment
  the schema moves.
