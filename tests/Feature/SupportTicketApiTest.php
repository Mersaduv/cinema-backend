<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_ticket_with_uploaded_image(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $upload = $this->post('/api/upload', [
            'assetType' => 'image',
            'releaseYear' => 2026,
            'file' => UploadedFile::fake()->image('ticket.png'),
        ], [
            'Accept' => 'application/json',
        ]);

        $upload
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['fileDirectory', 'fileName'])
            ->assertJsonPath('fileDirectory', '/images/ticket-images');

        $uploadedFilename = (string) $upload->json('fileName');
        $physicalPath = config('cinema_images.storage_root')
            . DIRECTORY_SEPARATOR
            . 'images'
            . DIRECTORY_SEPARATOR
            . 'ticket-images'
            . DIRECTORY_SEPARATOR
            . $uploadedFilename;

        $this->assertFileExists($physicalPath);

        $response = $this->postJson('/api/tickets', [
            'category' => 'technical',
            'description' => 'عدم دریافت ایمیل برای بازیابی رمز عبور',
            'image_directory' => $upload->json('fileDirectory'),
            'image_filename' => $upload->json('fileName'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ticket.status', SupportTicket::STATUS_WAITING)
            ->assertJsonPath('data.ticket.image_url', '/images/ticket-images/' . $uploadedFilename);

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $user->id,
            'category' => 'technical',
            'status' => SupportTicket::STATUS_WAITING,
        ]);
    }

    public function test_user_can_list_own_tickets_with_summary(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش فیلم',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'suggestion',
            'category_label' => 'پیشنهاد',
            'description' => 'پیشنهاد افزودن فیلم جدید',
            'status' => SupportTicket::STATUS_ANSWERED,
            'admin_reply' => 'بررسی شد.',
            'answered_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonPath('data.summary.waiting', 1)
            ->assertJsonPath('data.summary.answered', 1)
            ->assertJsonPath('data.summary.closed', 0)
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_close_ticket(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::query()->where('slug', 'admin')->first());

        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'account',
            'category_label' => 'حساب کاربری',
            'description' => 'عدم دریافت ایمیل',
            'status' => SupportTicket::STATUS_ANSWERED,
            'admin_reply' => 'بررسی شد',
            'answered_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/tickets/{$ticket->id}", [
            'status' => SupportTicket::STATUS_CLOSED,
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.status', SupportTicket::STATUS_CLOSED);
    }

    public function test_user_can_close_answered_ticket(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش',
            'status' => SupportTicket::STATUS_ANSWERED,
            'admin_reply' => 'پاسخ داده شد',
            'answered_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/tickets/{$ticket->id}/close")
            ->assertOk()
            ->assertJsonPath('data.ticket.status', SupportTicket::STATUS_CLOSED);
    }

    public function test_user_can_send_follow_up_message(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش',
            'status' => SupportTicket::STATUS_ANSWERED,
            'admin_reply' => 'پاسخ داده شد',
            'answered_at' => now(),
        ]);

        \App\Models\SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => 'user',
            'sender_user_id' => $user->id,
            'body' => 'مشکل در پخش',
        ]);

        \App\Models\SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => 'admin',
            'sender_user_id' => null,
            'body' => 'پاسخ داده شد',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/tickets/{$ticket->id}/messages", [
            'body' => 'هنوز مشکل دارم، لطفاً بررسی کنید.',
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.status', SupportTicket::STATUS_WAITING)
            ->assertJsonCount(3, 'data.ticket.messages');
    }

    public function test_user_ticket_list_does_not_include_messages(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش فیلم',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        \App\Models\SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => 'user',
            'sender_user_id' => $user->id,
            'body' => 'مشکل در پخش فیلم',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonPath('data.tickets.0.last_message_preview', 'مشکل در پخش فیلم')
            ->assertJsonMissingPath('data.tickets.0.messages');
    }

    public function test_user_ticket_show_includes_messages(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش فیلم',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        \App\Models\SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => 'user',
            'sender_user_id' => $user->id,
            'body' => 'مشکل در پخش فیلم',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.ticket.messages');
    }

    public function test_admin_ticket_list_does_not_include_messages(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::query()->where('slug', 'admin')->first());

        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'account',
            'category_label' => 'حساب کاربری',
            'description' => 'عدم دریافت ایمیل',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        \App\Models\SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => 'user',
            'sender_user_id' => $ticket->user_id,
            'body' => 'عدم دریافت ایمیل',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/tickets')
            ->assertOk()
            ->assertJsonPath('data.tickets.0.last_message_preview', 'عدم دریافت ایمیل')
            ->assertJsonMissingPath('data.tickets.0.messages');
    }

    public function test_admin_can_view_ticket_detail_with_messages(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::query()->where('slug', 'admin')->first());

        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'account',
            'category_label' => 'حساب کاربری',
            'description' => 'عدم دریافت ایمیل',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        \App\Models\SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => 'user',
            'sender_user_id' => $ticket->user_id,
            'body' => 'عدم دریافت ایمیل',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.ticket.messages')
            ->assertJsonPath('data.ticket.user.email', $ticket->user->email);
    }

    public function test_user_can_send_message_with_attachment_only(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['status' => 'active']);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => 'technical',
            'category_label' => 'مشکل فنی',
            'description' => 'مشکل در پخش',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        Sanctum::actingAs($user);

        $upload = $this->post('/api/upload', [
            'assetType' => 'ticket_attachment',
            'releaseYear' => 2026,
            'file' => UploadedFile::fake()->image('evidence.png'),
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $this->postJson("/api/tickets/{$ticket->id}/messages", [
            'image_directory' => $upload->json('fileDirectory'),
            'image_filename' => $upload->json('fileName'),
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.messages.0.body', 'پیوست تصویر');
    }

    public function test_admin_can_reply_to_ticket(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::query()->where('slug', 'admin')->first());

        $ticket = SupportTicket::query()->create([
            'user_id' => User::factory()->create()->id,
            'category' => 'account',
            'category_label' => 'حساب کاربری',
            'description' => 'عدم دریافت ایمیل',
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/tickets/{$ticket->id}", [
            'admin_reply' => 'ایمیل ارسال شد، لطفاً پوشه اسپم را هم بررسی کنید.',
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.status', SupportTicket::STATUS_ANSWERED)
            ->assertJsonCount(1, 'data.ticket.messages');
    }
}
