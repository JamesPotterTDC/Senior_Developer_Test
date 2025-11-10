<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ValidationExamplesTest extends TestCase
{
    use RefreshDatabase;

    #[RequiresPhpExtension('pdo_pgsql')]
    public function test_purchase_returns_422_when_payment_reference_too_long(): void
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
            'name' => 'Validation',
            'capacity' => 1,
        ]);

        $reserve = $this->postJson("/api/events/{$event->id}/reserve")->assertCreated();
        $reservationId = $reserve->json('id');

        $tooLong = str_repeat('x', 100);

        $this->postJson("/api/reservations/{$reservationId}/purchase", [
            'payment_reference' => $tooLong,
        ])->assertStatus(422)->assertJsonValidationErrors('payment_reference');
    }
}
