<?php

namespace App\Notifications;

use App\Models\AdviserRequest;
use App\Services\EmailLinkGenerator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdviserRequestAcceptedNotification extends Notification
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
            'type' => 'adviser_request_accepted',
            'title' => 'Adviser Request Accepted',
            'message' => 'Your adviser request for "'
                .$proposal->title
                .'" has been accepted by '
                .$faculty->name.'.',
            'url' => route('student.requests.index'),
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
            ->subject('Adviser Request Accepted - SKILLSYNC')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your research adviser request has been accepted.')
            ->line('Research Title: '.$proposal->title)
            ->line('Faculty Adviser: '.$faculty->name)
            ->line('Please log in to SKILLSYNC to view the updated status of your adviser request.')
            ->action(
                'View Adviser Request',
                app(EmailLinkGenerator::class)->route('student.requests.index')
            )
            ->salutation("Thank you,\nSKILLSYNC\nResearch Adviser Recommendation System");
    }
}
