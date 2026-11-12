<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // Mark one notification as read
    public function markAsRead(
        Request $request,
        string $notification
    ): RedirectResponse {

        $userNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        $userNotification->markAsRead();

        return back();
    }

    // Mark all notifications as read
    public function markAllAsRead(
        Request $request
    ): RedirectResponse {

        $request->user()
            ->unreadNotifications()
            ->update([
                'read_at' => now(),
            ]);

        return back();
    }
}
