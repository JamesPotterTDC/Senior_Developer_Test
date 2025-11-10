<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ReservationFlowTest extends TestCase
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
    public function test_can_reserve_and_purchase_ticket(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Postgres driver not configured.');
        }
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres not reachable: '.$e->getMessage());
        }

        $event = Event::create([
            'name' => 'Tech Conference',
            'capacity' => 3,
        ]);

        // Reserve a ticket
        $reserveResponse = $this->postJson("/api/events/{$event->id}/reserve");
        $reserveResponse->assertCreated();
        $reservationId = $reserveResponse->json('id');

        // Event stats should reflect a valid reservation
        $eventResponse = $this->getJson("/api/events/{$event->id}");
        $eventResponse
            ->assertOk()
            ->assertJson([
                'id' => $event->id,
                'capacity' => 3,
                'reserved' => 1,
                'purchased' => 0,
                'available' => 2,
            ]);

        // Purchase the reservation
        $purchaseResponse = $this->postJson("/api/reservations/{$reservationId}/purchase");
        $purchaseResponse
            ->assertOk()
            ->assertJson([
                'id' => $reservationId,
                'status' => Reservation::STATUS_PURCHASED,
            ]);

        // Event stats should reflect a purchase now
        $eventResponse = $this->getJson("/api/events/{$event->id}");
        $eventResponse
            ->assertOk()
            ->assertJson([
                'reserved' => 0,
                'purchased' => 1,
                'available' => 2,
            ]);
    }

    #[RequiresPhpExtension('pdo_pgsql')]
    public function test_reservation_cannot_be_purchased_if_expired(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Postgres driver not configured.');
        }
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres not reachable: '.$e->getMessage());
        }

        $event = Event::create([
            'name' => 'Concert',
            'capacity' => 1,
        ]);

        $reserveResponse = $this->postJson("/api/events/{$event->id}/reserve");
        $reserveResponse->assertCreated();
        $reservationId = $reserveResponse->json('id');

        // Force expire the reservation
        $reservation = Reservation::findOrFail($reservationId);
        $reservation->update(['expires_at' => Carbon::now()->subMinute()]);

        $purchaseResponse = $this->postJson("/api/reservations/{$reservationId}/purchase");
        $purchaseResponse->assertStatus(409)->assertJson([
            'error' => 'invalid_or_expired_reservation',
        ]);
    }
}
