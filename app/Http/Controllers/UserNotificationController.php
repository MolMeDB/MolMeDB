<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $limit = (int) ($request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])['limit'] ?? 20);

        $notifications = UserNotification::query()
            ->whereBelongsTo($user)
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $notifications->map(fn (UserNotification $notification): array => [
                'id' => $notification->id,
                'state' => $notification->state,
                'title' => $notification->title,
                'body' => $notification->body,
                'created_at' => $notification->created_at?->toISOString(),
                'read_at' => $notification->read_at?->toISOString(),
            ]),
            'meta' => [
                'unread_count' => UserNotification::query()
                    ->whereBelongsTo($user)
                    ->where('state', UserNotification::STATE_NEW)
                    ->count(),
            ],
        ]);
    }

    /**
     * One notification of the signed-in user in full: the message as the
     * e-mail carries it (HTML) and the page it is about. Reading it does not
     * mark it as read, since a page may be loaded in advance; see markOneAsRead().
     */
    public function show(Request $request, int $notification): JsonResponse
    {
        $record = $this->ownNotification($request, $notification);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'state' => $record->state,
                'title' => $record->title,
                'body' => $record->body,
                'message' => filled($record->email_message) ? $record->email_message : $record->body,
                'url' => $record->relatedUrl(),
                'created_at' => $record->created_at?->toISOString(),
                'read_at' => $record->read_at?->toISOString(),
            ],
        ]);
    }

    public function markOneAsRead(Request $request, int $notification): JsonResponse
    {
        $this->ownNotification($request, $notification)->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    /**
     * Another user's notification answers 404, as a missing one does.
     */
    private function ownNotification(Request $request, int $notification): UserNotification
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        return UserNotification::query()
            ->whereBelongsTo($user)
            ->findOrFail($notification);
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        UserNotification::query()
            ->whereBelongsTo($user)
            ->where('state', UserNotification::STATE_NEW)
            ->update([
                'state' => UserNotification::STATE_READ,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Notifications marked as read.',
        ]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        UserNotification::query()
            ->whereBelongsTo($user)
            ->delete();

        return response()->json([
            'message' => 'Notifications cleared.',
        ]);
    }
}
