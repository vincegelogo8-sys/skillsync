<?php

namespace App\Notifications;

use App\Models\AdviserRequest;
use App\Services\EmailLinkGenerator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdviserRequestDeclinedNotification extends Notification
{
    public function __construct(public AdviserRequest $request) {}

    /**
     * Send through database and email channels.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Store the in-app notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $proposal = $this->request->researchProposal;
        $faculty = $this->request->facultyProfile->user;

        return [
            'type' => 'adviser_request_declined',
            'title' => 'Adviser Request Declined',
            'message' => 'Your adviser request for "'
                .$proposal->title
                .'" was declined by '
                .$faculty->name.'.',
            'url' => route(
                'student.recommendations.index',
                $this->request->research_proposal_id
            ),
            'request_id' => $this->request->id,
        ];
    }

    /**
     * Send the Gmail notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $proposal = $this->request->researchProposal;
        $faculty = $this->request->facultyProfile->user;

        return (new MailMessage)
            ->subject('Adviser Request Update - SKILLSYNC')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your research adviser request has been declined.')
            ->line('Research Title: '.$proposal->title)
            ->line('Faculty Adviser: '.$faculty->name)
            ->line('You may return to SKILLSYNC and review the other available adviser recommendations.')
            ->action(
                'View Adviser Recommendations',
                app(EmailLinkGenerator::class)->route(
                    'student.recommendations.index',
                    $this->request->research_proposal_id
                )
            )
            ->salutation("Thank you,\nSKILLSYNC\nResearch Adviser Recommendation System");
    }
}
