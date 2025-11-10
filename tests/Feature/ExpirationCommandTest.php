<?php

/**
 * Proves expiry runs deterministically with a fixed clock.
 * Old reservations flip to expired; fresh ones remain reserved.
 */

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ExpirationCommandTest extends TestCase
{
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql extension not available.');
        }
        try {
            new \PDO('pgsql:host=127.0.0.1;port=5432;dbname=laravel', 'laravel', 'secret');
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres not reachable: '.$e->getMessage());
        }
    }

    #[RequiresPhpExtension('pdo_pgsql')]
    public function test_expire_command_marks_old_reservations_as_expired(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Postgres driver not configured.');
        }
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres not reachable: '.$e->getMessage());
        }

        Carbon::setTestNow('2025-01-01 10:00:00');

        $event = Event::create([
            'name' => 'Expirable',
            'capacity' => 5,
        ]);

        // Create two reservations, one already expired, one not yet
        Reservation::create([
            'event_id' => $event->id,
            'status' => Reservation::STATUS_RESERVED,
            'expires_at' => Carbon::now()->subMinutes(1),
        ]);

        Reservation::create([
            'event_id' => $event->id,
            'status' => Reservation::STATUS_RESERVED,
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        // Run command
        $this->artisan('reservations:expire')->assertSuccessful();

        $this->assertSame(1, Reservation::where('status', Reservation::STATUS_EXPIRED)->count());
        $this->assertSame(1, Reservation::where('status', Reservation::STATUS_RESERVED)->count());
    }
}
