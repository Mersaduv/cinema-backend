<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Cinema\TicketAttachmentService;
use App\Services\Cinema\TicketLifecycleService;
use App\Services\Cinema\TicketMessageService;
use App\Services\Cinema\TicketPresentationService;
use App\Support\TicketValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class PublicTicketController extends Controller
{
    public function __construct(
        private readonly TicketPresentationService $presentationService,
        private readonly TicketAttachmentService $ticketAttachmentService,
        private readonly TicketLifecycleService $ticketLifecycleService,
        private readonly TicketMessageService $ticketMessageService,
    ) {
    }

    public function categories(): JsonResponse
    {
        $categories = collect(TicketValidation::CATEGORIES)
            ->map(fn (string $label, string $slug) => [
                'slug' => $slug,
                'label' => $label,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => ['categories' => $categories],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === 'banned') {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = SupportTicket::query()
            ->forUser((int) $user->id)
            ->with('latestMessage')
            ->withCount('messages')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $waiting = SupportTicket::query()
            ->forUser((int) $user->id)
            ->where('status', SupportTicket::STATUS_WAITING)
            ->count();

        $answered = SupportTicket::query()
            ->forUser((int) $user->id)
            ->where('status', SupportTicket::STATUS_ANSWERED)
            ->count();

        $closed = SupportTicket::query()
            ->forUser((int) $user->id)
            ->where('status', SupportTicket::STATUS_CLOSED)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'tickets' => collect($paginator->items())
                    ->map(fn (SupportTicket $ticket) => $this->presentationService->listTransform($ticket, $user))
                    ->values(),
                'summary' => [
                    'waiting' => $waiting,
                    'answered' => $answered,
                    'closed' => $closed,
                ],
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || (int) $supportTicket->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $this->presentationService->transform(
                    $supportTicket->load(['messages' => fn ($query) => $query->orderBy('created_at')]),
                    $user,
                ),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === 'banned') {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make(
            $request->all(),
            TicketValidation::submitRules(),
            [
                'category.required' => 'دسته‌بندی الزامی است.',
                'category.in' => 'دسته‌بندی معتبر نیست.',
                'description.required' => 'توضیحات الزامی است.',
                'description.min' => 'توضیحات خیلی کوتاه است.',
            ],
        )->validate();

        $categoryLabel = TicketValidation::categoryLabel($validated['category']);

        if ($categoryLabel === null) {
            return response()->json([
                'success' => false,
                'message' => 'دسته‌بندی معتبر نیست.',
            ], 422);
        }

        $imageFilename = filled($validated['image_filename'] ?? null)
            ? (string) $validated['image_filename']
            : null;

        $imagePath = $imageFilename
            ? $this->ticketAttachmentService->publicPathFromFilename($imageFilename)
            : null;

        $imageUrl = $imagePath
            ? $this->ticketAttachmentService->resolveDisplayUrl($imagePath)
            : null;

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'category' => $validated['category'],
            'category_label' => $categoryLabel,
            'description' => TicketValidation::sanitizeDescription($validated['description']),
            'image_path' => $imagePath,
            'image_url' => $imageUrl,
            'status' => SupportTicket::STATUS_WAITING,
        ]);

        $this->ticketMessageService->createInitialUserMessage(
            $ticket,
            $user,
            $ticket->description,
            $imagePath,
            $imageUrl,
        );

        return response()->json([
            'success' => true,
            'message' => 'تیکت شما با موفقیت ثبت شد.',
            'data' => [
                'ticket' => $this->presentationService->transform($ticket->fresh('messages'), $user),
            ],
        ], 201);
    }

    public function storeMessage(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || (int) $supportTicket->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = Validator::make(
            $request->all(),
            TicketValidation::messageRules(),
        )->validate();

        $imageFilename = filled($validated['image_filename'] ?? null)
            ? (string) $validated['image_filename']
            : null;
        $body = (string) ($validated['body'] ?? '');

        if (! $imageFilename && trim($body) === '') {
            return response()->json([
                'success' => false,
                'message' => 'متن پیام یا پیوست الزامی است.',
            ], 422);
        }

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
            $this->ticketMessageService->createUserFollowUp(
                $supportTicket,
                $user,
                $body,
                $imagePath,
                $imageUrl,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'پیام شما ارسال شد.',
            'data' => [
                'ticket' => $this->presentationService->transform(
                    $supportTicket->fresh(['messages' => fn ($query) => $query->orderBy('created_at')]),
                    $user,
                ),
            ],
        ]);
    }

    public function close(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || (int) $supportTicket->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        try {
            $updates = $this->ticketLifecycleService->buildUserCloseUpdates($supportTicket);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $supportTicket->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'تیکت بسته شد.',
            'data' => [
                'ticket' => $this->presentationService->transform($supportTicket->fresh(), $user),
            ],
        ]);
    }
}
