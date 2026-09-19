<?php

namespace App\Services;

use App\Models\AdviserRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdviserRequestNotificationService
{
    /** Register inside the workflow transaction; discarded if it rolls back. */
    public function afterCommit(AdviserRequest $request, string $notificationClass, string $recipientRelation): void
    {
        DB::afterCommit(function () use ($request, $notificationClass, $recipientRelation) {
            try {
                $request->load(['studentProfile.user', 'facultyProfile.user', 'researchProposal']);
                $request->{$recipientRelation}->user->notify(new $notificationClass($request));
            } catch (Throwable $exception) {
                // SMTP exceptions may contain server responses; log identifiers only.
                try {
                    Log::warning('Adviser request email could not be delivered.', [
                        'adviser_request_id' => $request->id,
                        'notification' => $notificationClass,
                        'exception_type' => $exception::class,
                    ]);
                } catch (Throwable) {
                    // A logging failure must not turn a committed action into an error.
                }
            }
        });
    }
}
