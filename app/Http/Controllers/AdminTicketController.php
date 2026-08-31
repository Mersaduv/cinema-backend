<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\Cinema\TicketAttachmentService;
use App\Services\Cinema\TicketLifecycleService;
use App\Services\Cinema\TicketMessageService;
use App\Services\Cinema\TicketPresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminTicketController extends Controller
{
    public function __construct(
        private readonly TicketPresentationService $presentationService,
        private readonly TicketLifecycleService $ticketLifecycleService,
        private readonly TicketMessageService $ticketMessageService,
        private readonly TicketAttachmentService $ticketAttachmentService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $paginator = SupportTicket::query()
            ->with(['viewer', 'latestMessage'])
            ->withCount('messages')
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', (string) $request->string('status'));
            })
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->string('search'));
                $query->where(function ($inner) use ($search): void {
                    $inner
                        ->where('description', 'like', '%' . $search . '%')
                        ->orWhereHas('viewer', fn ($viewerQuery) => $viewerQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%'));
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'tickets' => collect($paginator->items())
                    ->map(fn (SupportTicket $ticket) => $this->presentationService->adminListTransform($ticket))
                    ->values(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function show(SupportTicket $supportTicket): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $this->presentationService->adminTransform(
                    $supportTicket->load([
                        'viewer',
                        'messages' => fn ($query) => $query->orderBy('created_at'),
                    ]),
                ),
            ],
        ]);
    }

    public function update(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    SupportTicket::STATUS_WAITING,
                    SupportTicket::STATUS_ANSWERED,
                    SupportTicket::STATUS_CLOSED,
                ]),
            ],
            'admin_reply' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'image_directory' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image_filename' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $imageFilename = filled($validated['image_filename'] ?? null)
            ? (string) $validated['image_filename']
            : null;
        $replyBody = array_key_exists('admin_reply', $validated)
            ? (string) ($validated['admin_reply'] ?? '')
            : '';
        $hasReply = $imageFilename !== null || trim($replyBody) !== '';
        $hasStatus = array_key_exists('status', $validated);

        if ($hasReply) {
            $imagePath = $imageFilename
                ? $this->ticketAttachmentService->publicPathFromUpload(
                    $validated['image_directory'] ?? null,
                    $imageFilename,
                )
                : null;
            $imageUrl = $imagePath
                ? $this->ticketAttachmentService->resolveDisplayUrl($imagePath)
                : null;

            try {
                $this->ticketMessageService->createAdminReply(
                    $supportTicket,
                    $request->user(),
                    $replyBody,
                    $imagePath,
                    $imageUrl,
                );
                $supportTicket->refresh();
            } catch (InvalidArgumentException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        if ($hasStatus) {
            try {
                $updates = $this->ticketLifecycleService->buildAdminUpdates($supportTicket, [
                    'status' => $validated['status'],
                ]);
            } catch (InvalidArgumentException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            if ($updates !== []) {
                $supportTicket->update($updates);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'تیکت به‌روزرسانی شد.',
            'data' => [
                'ticket' => $this->presentationService->adminTransform(
                    $supportTicket->fresh(['viewer', 'messages' => fn ($query) => $query->orderBy('created_at')]),
                ),
            ],
        ]);
    }
}
