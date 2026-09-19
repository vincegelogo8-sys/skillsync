<?php

namespace App\Notifications;

use App\Models\AdviserRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdviserRequestDeclinedNotification extends Notification
{
    public function __construct(public AdviserRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Adviser Request Update - SKILLSYNC')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your research adviser request has been declined.')
            ->line('Research Title: '.$this->request->researchProposal->title)
            ->line('Faculty Adviser: '.$this->request->facultyProfile->user->name)
            ->line('You may return to SKILLSYNC and review the other available adviser recommendations.')
            ->action('View Adviser Recommendations', route('student.recommendations.index', $this->request->research_proposal_id))
            ->salutation("Thank you,\nSKILLSYNC\nResearch Adviser Recommendation System");
    }
}
