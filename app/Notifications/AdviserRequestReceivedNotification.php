<?php

namespace App\Notifications;

use App\Models\AdviserRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdviserRequestReceivedNotification extends Notification
{
    public function __construct(public AdviserRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->request->studentProfile->user;

        return (new MailMessage)
            ->subject('New Research Adviser Request - SKILLSYNC')
            ->replyTo($student->email, $student->name)
            ->greeting("Hello {$notifiable->name},")
            ->line("You have received a new research adviser request from {$this->request->studentProfile->user->name}.")
            ->line('Student Email: '.$student->email)
            ->line('Research Title: '.$this->request->researchProposal->title)
            ->line('The student has selected you as a potential research adviser through SKILLSYNC.')
            ->line('Please log in to SKILLSYNC to review the adviser request.')
            ->action('View Adviser Request', route('faculty.requests.index'))
            ->salutation("Thank you,\nSKILLSYNC\nResearch Adviser Recommendation System");
    }
}
