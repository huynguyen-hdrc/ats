<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteUser extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/accept-invite?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]));
        $hours = (int) round(config('auth.passwords.invites.expire') / 60);

        return (new MailMessage)
            ->subject('Mời tham gia Helidirect HR')
            ->greeting('Chào '.$notifiable->name.',')
            ->line('Bạn được mời sử dụng hệ thống Quản lý nhân sự Helidirect.')
            ->line('Bấm nút dưới đây để đặt mật khẩu và kích hoạt tài khoản.')
            ->action('Đặt mật khẩu', $url)
            ->line("Link dùng được một lần và hết hạn sau {$hours} giờ. Nếu hết hạn, nhờ phòng Nhân sự gửi lại lời mời.")
            ->salutation('Helidirect HR');
    }
}
