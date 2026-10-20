<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPassword extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]));
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Đặt lại mật khẩu Helidirect HR')
            ->greeting('Chào '.$notifiable->name.',')
            ->line('Có yêu cầu đặt lại mật khẩu cho tài khoản của bạn.')
            ->action('Đặt lại mật khẩu', $url)
            ->line("Link hết hạn sau {$minutes} phút.")
            ->line('Nếu bạn không yêu cầu, hãy bỏ qua thư này. Mật khẩu hiện tại vẫn giữ nguyên.')
            ->salutation('Helidirect HR');
    }
}
