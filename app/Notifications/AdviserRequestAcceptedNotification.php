<?php

namespace App\Notifications;

use App\Models\AdviserRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdviserRequestAcceptedNotification extends Notification
{
    public function __construct(public AdviserRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Adviser Request Accepted - SKILLSYNC')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your research adviser request has been accepted.')
            ->line('Research Title: '.$this->request->researchProposal->title)
            ->line('Faculty Adviser: '.$this->request->facultyProfile->user->name)
            ->line('Please log in to SKILLSYNC to view the updated status of your adviser request.')
            ->action('View Adviser Request', route('student.requests.index'))
            ->salutation("Thank you,\nSKILLSYNC\nResearch Adviser Recommendation System");
    }
}
