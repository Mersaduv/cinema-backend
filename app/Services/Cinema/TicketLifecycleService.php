<?php

namespace App\Services\Cinema;

use App\Models\SupportTicket;
use InvalidArgumentException;

class TicketLifecycleService
{
    /**
     * @param  array{status?: string, admin_reply?: string|null}  $input
     * @return array<string, mixed>
     */
    public function buildAdminUpdates(SupportTicket $ticket, array $input): array
    {
        $updates = [];

        if (array_key_exists('admin_reply', $input)) {
            $updates['admin_reply'] = $input['admin_reply'];
        }

        $targetStatus = $input['status'] ?? null;

        if (
            $targetStatus === null
            && array_key_exists('admin_reply', $input)
            && filled($input['admin_reply'])
        ) {
            $targetStatus = SupportTicket::STATUS_ANSWERED;
        }

        if ($targetStatus === null) {
            return $updates;
        }

        if (! in_array($targetStatus, $this->allowedStatuses(), true)) {
            throw new InvalidArgumentException('وضعیت تیکت معتبر نیست.');
        }

        $updates['status'] = $targetStatus;

        if ($targetStatus === SupportTicket::STATUS_WAITING) {
            $updates['closed_at'] = null;

            return $updates;
        }

        if (in_array($targetStatus, [SupportTicket::STATUS_ANSWERED, SupportTicket::STATUS_CLOSED], true)) {
            if ($ticket->answered_at === null) {
                $updates['answered_at'] = now();
            }
        }

        if ($targetStatus === SupportTicket::STATUS_CLOSED) {
            $updates['closed_at'] = now();
        } elseif ($ticket->status === SupportTicket::STATUS_CLOSED) {
            $updates['closed_at'] = null;
        }

        return $updates;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserCloseUpdates(SupportTicket $ticket): array
    {
        if ($ticket->status !== SupportTicket::STATUS_ANSWERED) {
            throw new InvalidArgumentException('فقط تیکت‌های پاسخ‌داده‌شده قابل بستن هستند.');
        }

        return [
            'status' => SupportTicket::STATUS_CLOSED,
            'closed_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserMessageUpdates(SupportTicket $ticket): array
    {
        return [
            'status' => SupportTicket::STATUS_WAITING,
            'closed_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAdminMessageUpdates(SupportTicket $ticket): array
    {
        $updates = [
            'status' => SupportTicket::STATUS_ANSWERED,
            'closed_at' => null,
        ];

        if ($ticket->answered_at === null) {
            $updates['answered_at'] = now();
        }

        return $updates;
    }

    /**
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        return [
            SupportTicket::STATUS_WAITING,
            SupportTicket::STATUS_ANSWERED,
            SupportTicket::STATUS_CLOSED,
        ];
    }
}
