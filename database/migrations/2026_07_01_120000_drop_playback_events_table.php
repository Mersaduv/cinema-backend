<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('playback_events');
    }

    public function down(): void
    {
        // Intentionally empty — QoE telemetry was removed from the platform.
    }
};
