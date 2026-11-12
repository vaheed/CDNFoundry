<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationChannelResource;
use App\Jobs\TestNotificationChannel;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\Operation;
use App\Models\User;
use App\Support\Notifications\ChannelData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Notification channels for the signed-in account. In the administrator routes (`admin/notification-channels`) the
 * channels are the platform's; in the customer routes they are the user's own and exist only while the switch is on.
 */
class NotificationChannelController extends Controller
{
    private function owner(Request $request): ?User
    {
        return $request->routeIs('admin.*') ? null : $request->user();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $owner = $this->owner($request);
        if ($owner !== null) {
            abort_if($owner->isAdmin(), 403, 'Administrator channels are managed under admin/notification-channels.');
        }

        return NotificationChannelResource::collection(
            NotificationChannel::query()->where('user_id', $owner?->getKey())->orderBy('created_at')->orderBy('id')->cursorPaginate(50),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        if ($owner !== null) {
            Gate::authorize('createOwn', NotificationChannel::class);
        }
        $data = ChannelData::validate($request->all(), $owner);
        $channel = DB::transaction(function () use ($request, $owner, $data): NotificationChannel {
            // Serialize concurrent creators so the per-owner bound holds.
            DB::table('system_settings')->where('group', 'notifications')->lockForUpdate()->first();
            ChannelData::assertRoom($owner);
            $channel = new NotificationChannel(['user_id' => $owner?->getKey(), 'type' => $data['type'], 'name' => $data['name'], 'destination_hint' => $data['hint'], 'events' => $data['events'], 'status' => 'active', 'consecutive_failures' => 0]);
            $channel->storeSecrets($data['secrets']);
            $channel->save();
            AuditLog::record($request->user(), 'notification_channel.created', $channel, ['type' => $channel->type, 'platform' => $owner === null], $request->ip());

            return $channel;
        });

        // The signing secret of a generic webhook is shown here once and never again.
        return NotificationChannelResource::make($channel)->additional(['meta' => array_filter(['signing_secret' => $data['signing_secret']])])->response()->setStatusCode(201);
    }

    public function destroy(Request $request, NotificationChannel $channel): JsonResponse
    {
        Gate::authorize('manage', $channel);
        $channel->delete();
        AuditLog::record($request->user(), 'notification_channel.deleted', null, ['channel_id' => $channel->id, 'type' => $channel->type, 'platform' => $channel->isPlatform()], $request->ip());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function resume(Request $request, NotificationChannel $channel): NotificationChannelResource
    {
        Gate::authorize('manage', $channel);
        $channel->forceFill(['status' => 'active', 'consecutive_failures' => 0, 'paused_reason' => null])->save();
        AuditLog::record($request->user(), 'notification_channel.resumed', null, ['channel_id' => $channel->id], $request->ip());

        return NotificationChannelResource::make($channel);
    }

    public function test(Request $request, NotificationChannel $channel): JsonResponse
    {
        Gate::authorize('manage', $channel);
        $operation = Operation::receipt('notification.test', ['channel_id' => $channel->id, 'user_id' => $request->user()->getKey()], $request->user()->getKey(), 'pending');
        TestNotificationChannel::dispatch($operation->id);

        return response()->json(['data' => ['operation_id' => $operation->id]], 202);
    }
}
