<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Csrf;

final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function money(float|int|string|null $value): string
    {
        return '$' . number_format((float) $value, 2);
    }

    public static function status(string $value): string
    {
        $label = ucwords(str_replace('_', ' ', $value));
        $class = match ($value) {
            'confirmed', 'completed', 'eligible', 'verified_enrolled', 'ready_to_notify', 'sent' => 'success',
            'potentially_ineligible', 'not_enrolled', 'failed', 'declined' => 'danger',
            'eligible_unmet_preference', 'insufficient_information', 'hold', 'needs_mapping' => 'warning',
            default => 'neutral',
        };

        return '<span class="status status-' . $class . '">' . self::e($label) . '</span>';
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(Csrf::token()) . '">';
    }

    public static function layout(
        string $title,
        string $body,
        ?string $userName = null,
        ?string $role = null,
        ?string $cycleLabel = null
    ): string {
        $safeTitle = self::e($title);
        $userHtml = $userName
            ? '<div class="user-block"><strong>' . self::e($userName) . '</strong>'
                . ($role ? '<span>' . self::e(ucwords(str_replace('_', ' ', $role))) . '</span>' : '')
                . '</div>'
            : '';

        $cycleHtml = $cycleLabel
            ? '<span class="cycle-pill">' . self::e($cycleLabel) . '</span>'
            : '';

        $nav = $userName ? self::nav($role) : '';
        $shellClass = $userName ? 'app-shell' : 'app-shell public-shell';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle} | Scholarship Manager</title>
    <style>
        :root {
            font-family: Arial, Helvetica, sans-serif;
            color: #151515;
            background: #f4f4f4;
            --iowa-gold: #ffcd00;
            --border: #d7d7d7;
            --focus: #005ea8;
            --muted: #666;
            --surface: #fff;
        }
        * { box-sizing: border-box; }
        body { margin: 0; line-height: 1.5; }
        .skip { position: absolute; left: -9999px; top: 0; background: #fff; color: #000; padding: .75rem; z-index: 20; }
        .skip:focus { left: .75rem; top: .75rem; }
        header { background: #000; color: #fff; border-bottom: 6px solid var(--iowa-gold); }
        .header-inner { max-width: 90rem; margin: 0 auto; padding: .9rem 1.25rem; display: flex; gap: 1rem; align-items: center; justify-content: space-between; }
        .brand { font-weight: 700; }
        .brand .iowa { color: var(--iowa-gold); letter-spacing: .06em; margin-right: .8rem; }
        .header-meta { display: flex; gap: 1rem; align-items: center; }
        .user-block { display: flex; flex-direction: column; text-align: right; font-size: .88rem; }
        .user-block span { color: #ddd; }
        .cycle-pill { border: 1px solid #777; border-radius: 999px; padding: .2rem .65rem; font-size: .82rem; }
        .app-shell { max-width: 90rem; margin: 0 auto; display: grid; grid-template-columns: 15rem 1fr; min-height: calc(100vh - 69px); }
        .app-shell.public-shell { grid-template-columns: 1fr; max-width: 50rem; min-height: auto; }
        nav { background: #fff; border-right: 1px solid var(--border); padding: 1rem; }
        nav a { display: block; padding: .58rem .7rem; border-radius: .3rem; color: #151515; text-decoration: none; font-weight: 600; }
        nav a:hover { background: #f0f0f0; }
        main { padding: 1.5rem; min-width: 0; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: .5rem; padding: 1.25rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem; }
        .stat { background: #fff; border: 1px solid var(--border); border-radius: .45rem; padding: 1rem; }
        .stat strong { display: block; font-size: 1.6rem; }
        .stat span { color: var(--muted); }
        .page-header { display: flex; gap: 1rem; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .page-header h1 { margin: 0; }
        .page-header p { margin: .2rem 0 0; color: var(--muted); }
        .actions { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        button, .button { display: inline-block; padding: .58rem .85rem; background: #111; color: #fff; border: 2px solid #111; border-radius: .28rem; font-weight: 700; text-decoration: none; cursor: pointer; font: inherit; }
        button.secondary, .button.secondary { background: #fff; color: #111; }
        button.small, .button.small { padding: .35rem .6rem; font-size: .9rem; }
        button:hover, .button:hover { background: #333; color: #fff; }
        label { display: block; font-weight: 700; margin: .8rem 0 .25rem; }
        input, select, textarea { width: 100%; padding: .6rem; border: 1px solid #777; border-radius: .25rem; font: inherit; background: #fff; }
        input[type="checkbox"], input[type="radio"] { width: auto; }
        textarea { min-height: 7rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: .75rem 1rem; }
        .form-actions { margin-top: 1rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: .7rem .65rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { background: #f7f7f7; font-size: .9rem; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: .45rem; background: #fff; }
        .status { display: inline-block; border-radius: 999px; padding: .15rem .55rem; font-size: .82rem; font-weight: 700; }
        .status-success { background: #e7f5ea; color: #155724; }
        .status-danger { background: #fde8e8; color: #8b1111; }
        .status-warning { background: #fff4d6; color: #6d4f00; }
        .status-neutral { background: #ececec; color: #333; }
        .notice { border-left: 4px solid #005ea8; background: #eef6ff; padding: .8rem 1rem; margin-bottom: 1rem; }
        .error { border-left: 4px solid #b50909; background: #fff1f1; padding: .8rem 1rem; margin-bottom: 1rem; }
        .success-box { border-left: 4px solid #2e8540; background: #edf8ef; padding: .8rem 1rem; margin-bottom: 1rem; }
        .muted { color: var(--muted); }
        .checklist { display: grid; gap: .7rem; }
        .check-item { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center; padding: .8rem; border: 1px solid var(--border); border-radius: .4rem; background: #fff; }
        .criteria { margin: 0; padding-left: 1.25rem; }
        details { border: 1px solid var(--border); border-radius: .35rem; padding: .6rem .8rem; background: #fff; }
        summary { cursor: pointer; font-weight: 700; }
        a { color: #005ea8; }
        a:focus, button:focus, input:focus, select:focus, textarea:focus, summary:focus { outline: 3px solid var(--focus); outline-offset: 2px; }
        @media (max-width: 800px) {
            .app-shell { grid-template-columns: 1fr; }
            nav { border-right: 0; border-bottom: 1px solid var(--border); display: flex; gap: .25rem; overflow-x: auto; }
            nav a { white-space: nowrap; }
            .header-meta { display: none; }
            main { padding: 1rem; }
            .page-header { flex-direction: column; }
        }
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to main content</a>
<header>
    <div class="header-inner">
        <div class="brand"><span class="iowa">IOWA</span> College of Education Scholarship Manager</div>
        <div class="header-meta">{$cycleHtml}{$userHtml}</div>
    </div>
</header>
<div class="{$shellClass}">
    {$nav}
    <main id="main">{$body}</main>
</div>
</body>
</html>
HTML;
    }

    private static function nav(?string $role): string
    {
        if ($role === 'student') {
            return <<<'HTML'
<nav aria-label="Recipient navigation">
    <a href="/portal">My Awards</a>
</nav>
HTML;
        }

        if (in_array($role, ['program_coordinator', 'department_chair'], true)) {
            return <<<'HTML'
<nav aria-label="Primary">
    <a href="/dashboard">Dashboard</a>
    <a href="/review">Program Review</a>
    <a href="/thank-yous/review">Thank-you Review</a>
</nav>
HTML;
        }

        return <<<'HTML'
<nav aria-label="Primary">
    <a href="/dashboard">Dashboard</a>
    <a href="/review">Program Review</a>
    <a href="/thank-yous/review">Thank-you Review</a>
    <a href="/admin/cycles">Cycles</a>
    <a href="/admin/cycle-report">Cycle Report</a>
    <a href="/admin/history-imports">History Import</a>
    <a href="/admin/planning">Planning</a>
    <a href="/admin/renewals">Renewals</a>
    <a href="/admin/allocations">Allocations</a>
    <a href="/admin/reviewers">Reviewers</a>
    <a href="/admin/users">Users</a>
    <a href="/admin/rubrics">Rubrics</a>
    <a href="/admin/applicants">Applicants</a>
    <a href="/admin/enrollment">Enrollment</a>
    <a href="/dean/recommendations">Dean Review</a>
    <a href="/dean/reallocation">Reallocation</a>
    <a href="/dean/verification">Verification</a>
    <a href="/admin/distribution-requests">Distribution Requests</a>
    <a href="/admin/thank-yous">Thank-you Letters</a>
    <a href="/admin/thank-you-reminders">Thank-you Reminders</a>
    <a href="/admin/thank-you-reviewers">Thank-you Reviewers</a>
    <a href="/notifications/ready">Notify</a>
    <a href="/notifications/history">Notification History</a>
    <a href="/admin/templates">Templates</a>
</nav>
HTML;
    }
}
