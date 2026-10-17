<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * Password reset token.
     */
    protected string $token;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Notification channels.
     */
    public function via($notifiable): array
    {
        return [
            'mail',
        ];
    }

    /**
     * Build the email.
     */
    public function toMail($notifiable): MailMessage
    {
        $url = route('admin.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('QueueFlow TMC - Reset Your Password')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line(
                'We received a request to reset the password for your QueueFlow TMC Administrator account.'
            )
            ->action(
                'Reset Password',
                $url
            )
            ->line(
                'This password reset link will expire in 60 minutes.'
            )
            ->line(
                'If you did not request a password reset, no further action is required.'
            )
            ->salutation('Regards, QueueFlow TMC');
    }
}