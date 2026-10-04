<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Env;
use App\Support\View;
use RuntimeException;

final class TestMailController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->currentCycle();

        if ((Env::get('APP_ENV','development') ?? 'development') === 'production') {
            http_response_code(404);
            throw new RuntimeException('Test mail viewer is unavailable in production.');
        }

        if ((Env::get('MAIL_DRIVER','log') ?? 'log') !== 'log') {
            return $this->render(
                'Test mail',
                '<div class="notice">MAIL_DRIVER is not set to <code>log</code>, so the testing mail viewer is disabled.</div>',
                $cycle
            );
        }

        $path = dirname(__DIR__, 3) . '/storage/logs/mail.log';
        $messages = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_slice(array_reverse($lines), 0, 50);

            foreach ($lines as $line) {
                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($record)) {
                        $messages[] = $record;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $cards = '';
        foreach ($messages as $message) {
            $attachments = '';
            foreach (($message['attachments'] ?? []) as $attachment) {
                $attachments .= '<li>' . View::e($attachment['name'] ?? 'Attachment') . '</li>';
            }

            $cards .= '<section class="card" style="margin-bottom:1rem">'
                . '<div class="page-header"><div><h2>' . View::e($message['subject'] ?? '(No subject)') . '</h2>'
                . '<p>To: ' . View::e($message['to'] ?? '') . '</p></div>'
                . '<span class="muted">' . View::e($message['sent_at'] ?? '') . '</span></div>'
                . '<details><summary>View HTML source</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'
                . View::e($message['html_body'] ?? '') . '</pre></details>'
                . ($attachments !== '' ? '<h3>Attachments</h3><ul>' . $attachments . '</ul>' : '')
                . '</section>';
        }

        if ($cards === '') {
            $cards = '<div class="notice">No log-mail messages have been generated yet. Queue a notification and run <code>php bin/console jobs:work</code>.</div>';
        }

        $body = '<div class="page-header"><div><h1>Testing mail</h1>'
            . '<p>Newest 50 messages captured by the safe log-only mail provider. These messages were not sent externally.</p></div></div>'
            . $cards;

        return $this->render('Testing mail', $body, $cycle);
    }
}
