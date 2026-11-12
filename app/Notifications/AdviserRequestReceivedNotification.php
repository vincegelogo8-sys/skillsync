<?php

namespace App\Notifications;

use App\Models\AdviserRequest;
use App\Services\EmailLinkGenerator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdviserRequestReceivedNotification extends Notification
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
        $student = $this->request->studentProfile->user;
        $proposal = $this->request->researchProposal;

        return [
            'type' => 'adviser_request_received',
            'title' => 'New Adviser Request',
            'message' => $student->name
                .' has requested you as a research adviser for "'
                .$proposal->title.'".',
            'url' => route('faculty.requests.index'),
            'request_id' => $this->request->id,
        ];
    }

    /**
     * Send the existing Gmail notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->request->studentProfile->user;
        $proposal = $this->request->researchProposal;

        return (new MailMessage)
            ->subject('New Research Adviser Request - SKILLSYNC')
            ->replyTo($student->email, $student->name)
            ->greeting("Hello {$notifiable->name},")
            ->line("You have received a new research adviser request from {$student->name}.")
            ->line('Student Email: '.$student->email)
            ->line('Research Title: '.$proposal->title)
            ->line('The student has selected you as a potential research adviser through SKILLSYNC.')
            ->line('Please log in to SKILLSYNC to review the adviser request.')
            ->action(
                'View Adviser Request',
                app(EmailLinkGenerator::class)->route('faculty.requests.index')
            )
            ->salutation("Thank you,\nSKILLSYNC\nResearch Adviser Recommendation System");
    }
}
