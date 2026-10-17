<?php

namespace App\Notifications;

use App\Models\PasswordResetCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries the student app's 6-digit reset code.
 *
 * Deliberately not a link: the app has no browser to redirect into, so the
 * student reads the digits out of their inbox and types them into the app.
 * The code is valid for PasswordResetCode::TTL_MINUTES.
 */
class StudentResetCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $code,
        protected int $ttlMinutes = PasswordResetCode::TTL_MINUTES
    ) {}

    /**
     * Notification channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email.
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('QueueFlow TMC - Your password reset code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line(
                'We received a request to reset the password for your QueueFlow TMC student account.'
            )
            ->line('Enter this code in the app to choose a new password:')
            ->line($this->code)
            ->line('The code expires in '.$this->ttlMinutes.' minutes.')
            ->line(
                'If you did not request a password reset, you can ignore this email. '.
                'Your current password still works.'
            )
            ->salutation('Regards, QueueFlow TMC');
    }
}
