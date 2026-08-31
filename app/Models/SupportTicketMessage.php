<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'support_ticket_id',
        'sender_role',
        'sender_user_id',
        'sender_viewer_id',
        'body',
        'image_path',
        'image_url',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderViewer(): BelongsTo
    {
        return $this->belongsTo(Viewer::class, 'sender_viewer_id');
    }
}
