<?php

/**
 * Proves oversell prevention under shell-driven parallel curls and purchase idempotency.
 * One request wins the race; the rest get a safe 409.
 */

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReservationOversellPreventionTest extends TestCase
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
    public function test_parallel_reserve_requests_do_not_exceed_capacity(): void
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
            'name' => 'Stress Test',
            'capacity' => 10,
        ]);

        $host = '127.0.0.1';
        $port = 8082;
        $publicPath = base_path('public');

        $server = new Process(['php', '-S', "{$host}:{$port}", '-t', $publicPath]);
        $server->start();
        usleep(300000); // wait 300ms

        $cmd = sprintf(
            'seq 1 50 | xargs -P 50 -I {} curl -s -o /dev/null -w "%%{http_code}\n" -X POST http://%s:%d/api/events/%d/reserve',
            $host,
            $port,
            $event->id
        );
        $client = Process::fromShellCommandline($cmd);
        $client->run();

        $server->stop(0);

        $output = trim($client->getOutput());
        $codes = array_filter(explode("\n", $output));
        $success = count(array_filter($codes, fn ($c) => $c === '201'));

        $this->assertTrue($success <= 10, "Success count should be <= capacity, got {$success}");

        $activeCount = Reservation::where('event_id', $event->id)
            ->whereIn('status', [Reservation::STATUS_RESERVED, Reservation::STATUS_PURCHASED])
            ->count();
        $this->assertTrue($activeCount <= 10, "Active reservations should be <= capacity, got {$activeCount}");
    }

    #[RequiresPhpExtension('pdo_pgsql')]
    public function test_purchase_race_is_idempotent(): void
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
            'name' => 'Race',
            'capacity' => 1,
        ]);

        $reserve = $this->postJson("/api/events/{$event->id}/reserve")->assertCreated();
        $reservationId = $reserve->json('id');

        $host = '127.0.0.1';
        $port = 8083;
        $publicPath = base_path('public');

        $server = new Process(['php', '-S', "{$host}:{$port}", '-t', $publicPath]);
        $server->start();
        usleep(300000);

        $cmd = sprintf(
            'seq 1 10 | xargs -P 10 -I {} curl -s -o /dev/null -w "%%{http_code}\n" -X POST http://%s:%d/api/reservations/%d/purchase',
            $host,
            $port,
            $reservationId
        );
        $client = Process::fromShellCommandline($cmd);
        $client->run();
        $server->stop(0);

        $codes = array_filter(explode("\n", trim($client->getOutput())));
        $ok = count(array_filter($codes, fn ($c) => $c === '200'));
        $conflict = count(array_filter($codes, fn ($c) => $c === '409'));

        $this->assertSame(1, $ok, 'Exactly one purchase should succeed.');
        $this->assertSame(9, $conflict, 'Others should conflict due to idempotency.');
    }
}
