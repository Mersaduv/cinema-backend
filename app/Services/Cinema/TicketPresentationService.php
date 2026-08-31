<?php

namespace App\Services\Cinema;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Viewer;
use App\Support\TicketValidation;

class TicketPresentationService
{
    private const PREVIEW_LENGTH = 120;

    public function __construct(
        private readonly TicketAttachmentService $ticketAttachmentService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listTransform(SupportTicket $ticket, ?Viewer $viewer = null): array
    {
        $ticket->loadMissing('latestMessage');

        $latest = $ticket->latestMessage;
        $previewBody = $latest?->body ?? $ticket->description;

        return [
            'id' => $ticket->id,
            'category' => $ticket->category,
            'category_label' => $ticket->category_label,
            'subject' => TicketValidation::subjectFromDescription($ticket->description),
            'status' => $ticket->status,
            'image_path' => $ticket->image_path,
            'image_url' => $this->ticketAttachmentService->resolveDisplayUrl(
                $ticket->image_path,
                $ticket->image_url,
            ),
            'last_message_preview' => $this->previewText($previewBody),
            'last_message_at' => ($latest?->created_at ?? $ticket->created_at)?->toIso8601String(),
            'last_message_role' => $latest?->sender_role,
            'message_count' => (int) ($ticket->messages_count ?? 0),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'answered_at' => $ticket->answered_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'is_own' => $viewer instanceof Viewer && (int) $ticket->viewer_id === (int) $viewer->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(SupportTicket $ticket, ?Viewer $viewer = null): array
    {
        $ticket->loadMissing(['messages' => fn ($query) => $query->orderBy('created_at')]);

        return [
            'id' => $ticket->id,
            'category' => $ticket->category,
            'category_label' => $ticket->category_label,
            'subject' => TicketValidation::subjectFromDescription($ticket->description),
            'description' => $ticket->description,
            'image_path' => $ticket->image_path,
            'image_url' => $this->ticketAttachmentService->resolveDisplayUrl(
                $ticket->image_path,
                $ticket->image_url,
            ),
            'status' => $ticket->status,
            'admin_reply' => $ticket->admin_reply,
            'messages' => $ticket->messages
                ->map(fn (SupportTicketMessage $message) => $this->transformMessage($message))
                ->values()
                ->all(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'answered_at' => $ticket->answered_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'is_own' => $viewer instanceof Viewer && (int) $ticket->viewer_id === (int) $viewer->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformMessage(SupportTicketMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender_role' => $message->sender_role,
            'body' => $message->body,
            'image_path' => $message->image_path,
            'image_url' => $this->ticketAttachmentService->resolveDisplayUrl(
                $message->image_path,
                $message->image_url,
            ),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminListTransform(SupportTicket $ticket): array
    {
        $ticket->loadMissing('viewer');

        return [
            ...$this->listTransform($ticket),
            'viewer' => $ticket->viewer ? [
                'id' => $ticket->viewer->id,
                'name' => $ticket->viewer->name,
                'email' => $ticket->viewer->email,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminTransform(SupportTicket $ticket): array
    {
        $ticket->loadMissing('viewer');

        return [
            ...$this->transform($ticket),
            'viewer' => $ticket->viewer ? [
                'id' => $ticket->viewer->id,
                'name' => $ticket->viewer->name,
                'email' => $ticket->viewer->email,
            ] : null,
        ];
    }

    private function previewText(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (mb_strlen($normalized) <= self::PREVIEW_LENGTH) {
            return $normalized;
        }

        return mb_substr($normalized, 0, self::PREVIEW_LENGTH) . '…';
    }
}
