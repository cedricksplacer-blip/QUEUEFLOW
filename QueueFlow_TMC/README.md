# QueueFlow TMC

QueueFlow TMC is a Laravel 10 campus queue-management administration system.

## Admin features

- Dashboard with live queue statistics
- Queue management: generate, search, filter, call next, serve, cancel, delete
- Staff management: create, edit, assign office, activate/deactivate, delete
- User management: students, staff and admin accounts
- Office management
- Announcement management and publishing controls
- Date-range queue reports and office breakdowns
- System settings
- Live office queue display
- Per-office deployment flow: deploy an office, issue up to 100 tickets (PWD priority with evidence upload), then serve, complete and restart the batch
- Staff office queue console: each staff account is scoped to one assigned office and can call next (PWD first), mark served and monitor the running batch without touching the admin area

## Database

This project is configured for **MySQL / MySQL Server**. Create a database named `queueflow_tmc` in MySQL Workbench, then set the database credentials in `.env`.

Example:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=queueflow_tmc
DB_USERNAME=root
DB_PASSWORD=
```

## Install and run

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

> **Use `--host=0.0.0.0`, not a bare `php artisan serve`.**
> A bare `php artisan serve` binds the PHP development server to `127.0.0.1`
> (loopback) only. That is reachable from this PC and from nothing else, so the
> Flutter app on a phone - which must dial this PC's LAN address - gets a
> connection refused and reports *"Server Offline"* even though Laravel is
> running. `--host=0.0.0.0` listens on every interface, including the Wi-Fi
> adapter, which is what the phone needs. Equivalent: `composer serve:lan`.

Open on this PC:

- Admin login: `http://127.0.0.1:8000/admin/login`
- Admin dashboard: `http://127.0.0.1:8000/admin/dashboard`

Open from a phone / another device on the same Wi-Fi (see `.env`
`LAN_SERVER_URL`, default `http://192.168.1.154:8000`):

- Health probe: `http://192.168.1.154:8000/api/health`

### LAN / Flutter connectivity

The Flutter app talks to `http://<LAN_SERVER_URL host>:8000/api`. When the app
says *"Server Offline"* but the PC is on Wi-Fi, check these in order:

1. **Binding** - the server must be on `0.0.0.0:8000`. Confirm the terminal
   shows `Server running on [http://0.0.0.0:8000]`, or run in PowerShell:
   `Get-NetTCPConnection -LocalPort 8000 -State Listen` - the `LocalAddress`
   must be `0.0.0.0`, not `127.0.0.1`.
2. **The PC's IP** - `Get-NetIPAddress -AddressFamily IPv4` and confirm the
   Wi-Fi adapter matches `.env` `LAN_SERVER_URL`. If the lease changed, update
   `LAN_SERVER_URL`, and the app's `--dart-define=API_BASE_URL` if it was
   overridden at build time.
3. **Firewall** - inbound TCP 8000 must be allowed on the Private profile
   (the rule `Q-FlowTMC LAN (tcp 8000)` does this). Do not disable the
   firewall; allow the port.
4. **Same network / AP isolation** - both devices must be on the same Wi-Fi
   SSID, and the router must not have "AP isolation" / "client isolation"
   enabled.

The health probe is the source of truth: `Wi-Fi connected` is not the same as
`server reachable`. See `docs/` and the Flutter `ConnectivityService`.

## Seeded accounts

| Role | Email | Password |
|---|---|---|
| Admin | `admin@tmc.edu` | `password` |
| Staff | `registrar.staff@tmc.edu` | `password` |
| Staff | `cashier.staff@tmc.edu` | `password` |
| Student | `student@tmc.edu` | `password` |

Passwords are stored as **bcrypt hashes** and verified with `Hash::check`. A model-level mutator on `User` hashes any plaintext password on create/update, so every registration, staff/user/API create and password change is hashed automatically (already-hashed values are left untouched). A data migration hashes any legacy plain-text rows.

## Tests

A permanent regression suite lives in `tests/Feature/` (queue deployments, ticket creation with PWD evidence, serving flow, dashboard stats, auth, profile). It runs against a dedicated MySQL test database:

```bash
# create the test DB once (MySQL)
CREATE DATABASE queueflow_tmc_test;

# run the suite
php artisan test
```

## Role routing after login

- **Admin** – redirected to `/admin/dashboard` (full admin panel, `role:admin`).
- **Staff** – redirected to `/staff/queues`, their office queue console (`role:staff`). Staff are scoped to the office set on their account and cannot open the admin panel. Batch deployment (preparing/opening batches) remains admin-only.
- **Student** – redirected to `/student/queues`, their ticket desk.

## Important

- Do not commit the real `.env` file.
- The project uses `role:admin` middleware for the admin area.
- Office queue numbers use the format `OFFICECODE-001` (e.g. `REG-001`), scoped per deployment batch — they increment until the batch's 100-slot capacity is reached and reset when the batch is closed and a new one is deployed.

## React + API Admin Panel

QueueFlow TMC now includes a React admin panel backed by authenticated Laravel API endpoints.

### Implemented
- React dashboard with automatic GET fetching
- Asynchronous loading indicators and API error states
- CRUD API operations using GET, POST, PUT and DELETE for Users, Staff, Offices and Announcements
- Queue API with GET, POST, PUT and DELETE plus Call Next
- Reports API and Settings API
- React sidebar pages for Dashboard, Queue Management, Staff, Users, Offices, Announcements, Reports and Settings
- Existing MySQL database/models are reused; no database replacement is required

### Run after extracting
```bash
npm install
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

The admin sidebar opens the React panel at `/admin/react/{page}` after administrator login.
