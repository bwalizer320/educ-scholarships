<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Env;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailProvider implements MailProvider
{
    public function send(MailMessage $message): MailResult
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = Env::get('SMTP_HOST', '') ?? '';
            $mail->Port = (int) (Env::get('SMTP_PORT', '587') ?? '587');
            $mail->SMTPAuth = true;
            $mail->Username = Env::get('SMTP_USERNAME', '') ?? '';
            $mail->Password = Env::get('SMTP_PASSWORD', '') ?? '';

            $encryption = Env::get('SMTP_ENCRYPTION', 'tls');
            if ($encryption) {
                $mail->SMTPSecure = $encryption;
            }

            $mail->setFrom(
                Env::get('MAIL_FROM_ADDRESS', '') ?? '',
                Env::get('MAIL_FROM_NAME', 'University of Iowa College of Education') ?? ''
            );
            $mail->addAddress($message->to);
            $mail->Subject = $message->subject;
            $mail->isHTML(true);
            $mail->Body = $message->htmlBody;

            foreach ($message->attachments as $attachment) {
                $mail->addAttachment(
                    $attachment['path'],
                    $attachment['name'] ?? basename($attachment['path'])
                );
            }

            $mail->send();

            return new MailResult(true, $mail->getLastMessageID() ?: null);
        } catch (\Throwable $e) {
            return new MailResult(false, null, $e->getMessage());
        }
    }
}
