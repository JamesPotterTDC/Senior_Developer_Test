<?php

/**
 * Proves no oversell under parallel load against Postgres row locks.
 * Fifty parallel reserves compete; successes never exceed capacity.
 */

namespace Tests\Feature;

use App\Models\Event;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
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
    public function test_multiple_reservation_attempts_do_not_exceed_capacity(): void
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
            'name' => 'Big Game',
            'capacity' => 10,
        ]);

        // Start a built-in PHP server in the background
        $host = '127.0.0.1';
        $port = 8001;
        $publicPath = base_path('public');
        $process = new \Symfony\Component\Process\Process(['php', '-S', "{$host}:{$port}", '-t', $publicPath]);
        $process->start();
        usleep(200000); // wait 200ms to boot

        $client = new Client([
            'base_uri' => "http://{$host}:{$port}",
            'http_errors' => false,
            'timeout' => 5,
        ]);

        $concurrency = 50;
        $success = 0;
        $fail = 0;

        $requests = function () use ($event) {
            foreach (range(1, 50) as $i) {
                yield new GuzzleRequest('POST', "/api/events/{$event->id}/reserve");
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response) use (&$success, &$fail) {
                if ($response->getStatusCode() === 201) {
                    $success++;
                } else {
                    $fail++;
                }
            },
            'rejected' => function () use (&$fail) {
                $fail++;
            },
        ]);
        $pool->promise()->wait();

        // Stop server
        $process->stop(0);

        $this->assertTrue($success <= 10, "Successful reservations should be <= capacity, got {$success}");
        $this->assertSame(50, $success + $fail);

        $reservedOrPurchased = \App\Models\Reservation::whereIn('status', ['reserved', 'purchased'])->count();
        $this->assertTrue($reservedOrPurchased <= 10, "Total active reservations <= capacity, got {$reservedOrPurchased}");
    }
}
