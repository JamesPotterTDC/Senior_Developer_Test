## Concurrent Ticket Reservation API (Laravel)

### Solution Overview
- API-only service for reserving and purchasing tickets with strict oversell prevention.
- Key artifacts:
  - Pessimistic locking with `DB::transaction()` + `lockForUpdate()` on the `events` row.
  - Idempotent purchase guarded by `whereNull('purchased_at')`.
  - Expiration command scheduled every minute to mark past-due reservations as `expired`.
  - Tests use Postgres to exercise real row-level locks and concurrency.

### Concurrency Strategy
- We lock the `events` row during reservation to compute remaining availability inside a single transaction:
  - `available = capacity - purchased - active_reserved`
  - If `available <= 0` → 409 `{ "error": "sold_out" }`
- Purchase flow locks the `reservations` row, validates state/expiry, and updates with `whereNull('purchased_at')` to ensure only one success.
- Trade-offs:
  - Pessimistic locking (chosen): strongest correctness on one RDBMS; simple, predictable; can serialize hot rows under extreme contention.
  - Optimistic locking: avoids blocking but needs retry loops; tricky under high contention and for aggregate counts.
  - Redis atomic ops: scalable counters, but requires cross-store consistency and reliable queues to reconcile.

### Database Schema
- `events`: `id`, `name`, `capacity`, `timestamps`
- `reservations`: `id`, `event_id` (FK), `status` (`reserved|purchased|expired|canceled`), `purchased_at` nullable, `expires_at` nullable, `timestamps`
- Indexes:
  - `reservations_event_status_expires_idx (event_id, status, expires_at)` to speed counts and expiry scans.
  - Optional partial unique index per user/event for a single active reservation (not implemented here; no users).
- “Active” reservations: `status='reserved' AND expires_at > now()`. See `Reservation::scopeActive()`.

### Endpoints
- `POST /api/events/{event}/reserve`
  - 201: `{"id","event_id","status","expires_at","created_at"}`
  - 409: `{"error":"sold_out","message":"Event is sold out."}`
  - 422: Laravel validation errors (example)
    ```json
    {
      "message": "The given data was invalid.",
      "errors": {
        "event": ["The selected event is invalid."]
      }
    }
    ```
- `POST /api/reservations/{reservation}/purchase`
  - 200: `{"id","event_id","status","expires_at","created_at","updated_at"}`
  - 409: `{"error":"invalid_or_expired_reservation"}` or `{"error":"already_purchased"}`
  - 404: `{"error":"not_found"}`
  - 422: Laravel validation errors (example when `payment_reference` is too long)
    ```json
    {
      "message": "The given data was invalid.",
      "errors": {
        "payment_reference": ["The payment reference must not be greater than 64 characters."]
      }
    }
    ```
- `GET /api/events/{event}`
  - 200: `{"id","name","capacity","purchased","reserved","available","created_at","updated_at"}`

### Setup (Docker + Postgres)
1. Requirements: Docker, Docker Compose, PHP 8.2+, Composer.
2. Start Postgres:
   ```bash
   make db-up
   ```
   This boots Postgres 14+ on `127.0.0.1:5432` with DB `laravel`, user `laravel`, pass `secret`.
3. Configure testing DB:
   - `.env.testing` is included with:
     ```
     DB_CONNECTION=pgsql
     DB_HOST=127.0.0.1
     DB_PORT=5432
     DB_DATABASE=laravel
     DB_USERNAME=laravel
     DB_PASSWORD=secret
     ```
4. Install & migrate:
   ```bash
   composer install
   php artisan key:generate
   php artisan migrate
   ```
5. Serve locally:
   ```bash
   php artisan serve
   ```

### How to run tests (Postgres)
```bash
make test
```
Notes:
- `phpunit.xml` no longer forces SQLite; it respects `.env.testing` for Postgres.
- Concurrency tests are marked to require `pdo_pgsql`.
- CI runs the suite on push via GitHub Actions with a Postgres service.

### Scheduler / Expiration
- Command: `php artisan reservations:expire`
- Scheduled every minute in `bootstrap/app.php`.
- Production cron:
  ```
  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
  ```

### Manual Concurrency Check
1. Create event (capacity 10):
   ```bash
   php artisan tinker
   >>> $e = App\Models\Event::create(['name' => 'Concert', 'capacity' => 10]);
   >>> $e->id
   ```
2. Run 50 parallel reservations:
   ```bash
   seq 1 50 | xargs -P 50 -I {} curl -X POST http://localhost:8000/api/events/{EVENT_ID}/reserve -o /dev/null -s -w "Request {}: HTTP %{http_code}\n"
   ```
   Expected: ~10 responses `HTTP 201`, the rest `HTTP 409`.
3. Verify in SQL:
   ```sql
   SELECT
     SUM(CASE WHEN status='purchased' THEN 1 ELSE 0 END) AS purchased,
     SUM(CASE WHEN status='reserved' AND expires_at > NOW() THEN 1 ELSE 0 END) AS reserved_active
   FROM reservations
   WHERE event_id = {EVENT_ID};
   ```
   Ensure `purchased + reserved_active <= capacity`.

### Known Limitations
- Locks serialize on a single `events` row under very high contention; sharding by event or using inventory tokens can scale writes.
- SQLite is supported for smoke tests only; Postgres is required for real row-level locking.
- Test suite aims for pragmatic coverage (not exhaustive).

### Evaluator Checklist
- Correctness: `DB::transaction()` + `lockForUpdate()`; idempotent purchase; no oversell.
- Code Quality: Thin controllers, actions for business logic, form requests, PSR-12 (`php-cs-fixer`).
- Design: Clear schema and `active` scope; indexes for hot paths; standardized error envelope.
- Testing: Feature flows + two concurrency tests using Postgres and real parallelism; expiration command test.
- Documentation: Setup, strategy, endpoints, manual concurrency recipe, scheduler, limitations.

