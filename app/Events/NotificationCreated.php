<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PRD §4.9 "Badge counter pada bell icon di navbar, update real-time via
 * Echo private channel". Broadcast on the same `App.Models.User.{id}`
 * channel routes/channels.php already authorizes (owner only — never a
 * public channel, frontend-standards.md §5).
 *
 * `ShouldBroadcastNow` because it is only ever fired from inside
 * DeliverNotificationJob, which is already queued (after the caller's
 * transaction commits) and owns the retries — queuing the broadcast again
 * would just add a second hop. See NotificationService::deliver().
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return $this->notification->only(['id', 'type', 'title', 'message', 'metadata', 'priority', 'category', 'created_at']);
    }
}
