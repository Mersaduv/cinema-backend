<?php

namespace App\Services\Cinema;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Support\TicketValidation;
use InvalidArgumentException;

class TicketMessageService
{
    public function __construct(
        private readonly TicketAttachmentService $ticketAttachmentService,
        private readonly TicketLifecycleService $ticketLifecycleService,
    ) {
    }

    public function createInitialUserMessage(
        SupportTicket $ticket,
        User $user,
        string $body,
        ?string $imagePath = null,
        ?string $imageUrl = null,
    ): SupportTicketMessage {
        return $this->persistMessage(
            $ticket,
            SupportTicketMessage::ROLE_USER,
            $user,
            $body,
            $imagePath,
            $imageUrl,
        );
    }

    public function createUserFollowUp(
        SupportTicket $ticket,
        User $user,
        string $body,
        ?string $imagePath = null,
        ?string $imageUrl = null,
    ): SupportTicketMessage {
        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            throw new InvalidArgumentException('این تیکت بسته شده و امکان ارسال پیام جدید وجود ندارد.');
        }

        $message = $this->persistMessage(
            $ticket,
            SupportTicketMessage::ROLE_USER,
            $user,
            $body,
            $imagePath,
            $imageUrl,
        );

        $ticket->update(
            $this->ticketLifecycleService->buildUserMessageUpdates($ticket),
        );

        return $message;
    }

    public function createAdminReply(
        SupportTicket $ticket,
        User $admin,
        string $body,
        ?string $imagePath = null,
        ?string $imageUrl = null,
    ): SupportTicketMessage {
        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            throw new InvalidArgumentException('تیکت بسته شده است. ابتدا وضعیت را تغییر دهید.');
        }

        $message = $this->persistMessage(
            $ticket,
            SupportTicketMessage::ROLE_ADMIN,
            $admin,
            $body,
            $imagePath,
            $imageUrl,
        );

        $updates = $this->ticketLifecycleService->buildAdminMessageUpdates($ticket);
        $updates['admin_reply'] = $message->body;
        $ticket->update($updates);

        return $message;
    }

    private function persistMessage(
        SupportTicket $ticket,
        string $senderRole,
        ?User $sender,
        string $body,
        ?string $imagePath = null,
        ?string $imageUrl = null,
    ): SupportTicketMessage {
        if (! filled($imagePath) && ! filled($imageUrl)) {
            $sanitizedBody = TicketValidation::sanitizeDescription($body);
            if (mb_strlen($sanitizedBody) < TicketValidation::DESCRIPTION_MIN) {
                throw new InvalidArgumentException('متن پیام خیلی کوتاه است.');
            }
        } else {
            $sanitizedBody = TicketValidation::normalizeMessageBody($body, $imagePath);
        }

        return SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'sender_role' => $senderRole,
            'sender_user_id' => $sender?->id,
            'body' => $sanitizedBody,
            'image_path' => $imagePath,
            'image_url' => $imageUrl,
        ]);
    }

    public function resolveMessageImageUrl(?string $imagePath, ?string $imageUrl): ?string
    {
        return $this->ticketAttachmentService->resolveDisplayUrl($imagePath, $imageUrl);
    }
}
