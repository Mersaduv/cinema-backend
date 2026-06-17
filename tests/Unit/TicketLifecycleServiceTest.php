<?php

namespace Tests\Unit;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Cinema\TicketLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TicketLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reply_without_status_sets_answered(): void
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        $service = app(TicketLifecycleService::class);
        $updates = $service->buildAdminUpdates($ticket, [
            'admin_reply' => 'پاسخ پشتیبانی',
        ]);

        $this->assertSame(SupportTicket::STATUS_ANSWERED, $updates['status']);
        $this->assertArrayHasKey('answered_at', $updates);
    }

    public function test_admin_can_close_ticket_explicitly(): void
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش',
            'status' => SupportTicket::STATUS_ANSWERED,
            'admin_reply' => 'پاسخ داده شد',
            'answered_at' => now(),
        ]);

        $service = app(TicketLifecycleService::class);
        $updates = $service->buildAdminUpdates($ticket, [
            'status' => SupportTicket::STATUS_CLOSED,
        ]);

        $this->assertSame(SupportTicket::STATUS_CLOSED, $updates['status']);
        $this->assertArrayHasKey('closed_at', $updates);
    }

    public function test_user_can_only_close_answered_ticket(): void
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        $service = app(TicketLifecycleService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->buildUserCloseUpdates($ticket);
    }
}
