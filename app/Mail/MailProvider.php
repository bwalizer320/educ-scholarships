<?php

declare(strict_types=1);

namespace App\Mail;

interface MailProvider
{
    public function send(MailMessage $message): MailResult;
}
