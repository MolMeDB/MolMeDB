<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url:https', $this->publicHostRule()],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // An endpoint belongs to one browser profile: whoever subscribes it
        // last owns it, so a previous user of a shared browser stops getting
        // pushes there.
        PushSubscription::query()
            ->where('endpoint', $data['endpoint'])
            ->where('user_id', '!=', $user->id)
            ->delete();

        $user->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
            ],
        );

        return response()->json(['message' => 'Push subscription saved.']);
    }

    /**
     * The server posts every push to the endpoint, so it must name a public
     * push service, never an IP address or a local host name.
     */
    private function publicHostRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = strtolower(trim((string) parse_url((string) $value, PHP_URL_HOST), '[]'));

            $isLocal = $host === ''
                || filter_var($host, FILTER_VALIDATE_IP) !== false
                || $host === 'localhost'
                || ! str_contains($host, '.')
                || preg_match('/\.(localhost|local|internal|lan|home)$/', $host) === 1;

            if ($isLocal) {
                $fail('The push endpoint must be a public push service URL.');
            }
        };
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $user->pushSubscriptions()->where('endpoint', $data['endpoint'])->delete();

        return response()->json(['message' => 'Push subscription removed.']);
    }
}
