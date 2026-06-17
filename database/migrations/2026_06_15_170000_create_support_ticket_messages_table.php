<?php

use App\Models\SupportTicket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('sender_role', 16);
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });

        SupportTicket::query()
            ->orderBy('id')
            ->chunkById(100, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    $now = $ticket->created_at ?? now();

                    DB::table('support_ticket_messages')->insert([
                        'support_ticket_id' => $ticket->id,
                        'sender_role' => 'user',
                        'sender_user_id' => $ticket->user_id,
                        'body' => $ticket->description,
                        'image_path' => $ticket->image_path,
                        'image_url' => $ticket->image_url,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if (filled($ticket->admin_reply)) {
                        $answeredAt = $ticket->answered_at ?? $ticket->updated_at ?? now();

                        DB::table('support_ticket_messages')->insert([
                            'support_ticket_id' => $ticket->id,
                            'sender_role' => 'admin',
                            'sender_user_id' => null,
                            'body' => $ticket->admin_reply,
                            'image_path' => null,
                            'image_url' => null,
                            'created_at' => $answeredAt,
                            'updated_at' => $answeredAt,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
