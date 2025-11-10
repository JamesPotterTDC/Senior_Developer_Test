<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index(); // reserved | purchased | expired | canceled
            $table->timestamp('purchased_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            // Composite index to support expiration lookup and counts
            $table->index(['event_id', 'status', 'expires_at'], 'reservations_event_status_expires_idx');
            $table->index(['event_id', 'status'], 'reservations_event_status_idx');
        });

        // Add a check constraint on Postgres for status values
        try {
            $connection = Schema::getConnection()->getDriverName();
            if ($connection === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement("
                    ALTER TABLE reservations
                    ADD CONSTRAINT reservations_status_check
                    CHECK (status IN ('reserved','purchased','expired','canceled'))
                ");
            }
        } catch (\Throwable $e) {
            // ignore if not supported (e.g., sqlite) or already exists
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
