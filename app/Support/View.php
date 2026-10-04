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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #151515;
            background: #f6f7f9;
            --iowa-gold: #ffcd00;
            --ink: #111111;
            --text: #202124;
            --muted: #646a73;
            --border: #e2e5e9;
            --border-strong: #c9ced6;
            --focus: #005ea8;
            --surface: #ffffff;
            --surface-soft: #f8f9fb;
            --sidebar-hover: #f1f3f5;
            --shadow-sm: 0 1px 2px rgba(16, 24, 40, .04);
            --shadow-md: 0 4px 14px rgba(16, 24, 40, .06);
            --radius-sm: .5rem;
            --radius-md: .75rem;
            --radius-lg: 1rem;
        }
        * { box-sizing: border-box; }
        html { background: #f6f7f9; }
        body { margin: 0; line-height: 1.5; color: var(--text); background: #f6f7f9; font-size: 1rem; }
        h1, h2, h3 { color: var(--ink); letter-spacing: -.02em; }
        h1 { font-size: clamp(1.75rem, 2vw, 2.15rem); line-height: 1.15; }
        h2 { font-size: 1.25rem; }
        .skip { position: absolute; left: -9999px; top: 0; background: #fff; color: #000; padding: .75rem 1rem; z-index: 50; border-radius: .5rem; }
        .skip:focus { left: .75rem; top: .75rem; }

        header { background: #050505; color: #fff; border-bottom: 4px solid var(--iowa-gold); }
        .header-inner { width: 100%; margin: 0; padding: .78rem 1.65rem; display: flex; gap: 1rem; align-items: center; justify-content: space-between; min-height: 60px; }
        .brand { font-weight: 700; display: flex; align-items: center; gap: .75rem; font-size: .96rem; white-space: nowrap; }
        .brand .iowa { color: var(--iowa-gold); letter-spacing: .075em; margin-right: 0; font-weight: 800; }
        .header-meta { display: flex; gap: 1rem; align-items: center; }
        .user-block { display: flex; flex-direction: column; text-align: right; font-size: .82rem; line-height: 1.25; }
        .user-block strong { font-weight: 700; }
        .user-block span { color: #cfd2d6; font-size: .76rem; margin-top: .1rem; }
        .cycle-pill { border: 1px solid #52555a; background: #111; border-radius: 999px; padding: .24rem .65rem; font-size: .76rem; font-weight: 700; color: #fff; }

        .app-shell { width: 100%; margin: 0; display: grid; grid-template-columns: 16.75rem minmax(0, 1fr); min-height: calc(100vh - 64px); align-items: start; }
        .app-shell.public-shell { grid-template-columns: 1fr; max-width: 52rem; margin: 0 auto; min-height: auto; }

        nav { background: #fff; border-right: 1px solid var(--border); padding: 1rem .8rem 2rem; position: sticky; top: 0; height: calc(100vh - 64px); overflow-y: auto; scrollbar-width: thin; }
        .nav-section { margin: 0 0 1.15rem; }
        .nav-section:last-child { margin-bottom: 0; }
        .nav-section-label { display: block; margin: 0 .7rem .35rem; color: #8a9099; font-size: .68rem; line-height: 1.2; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        nav a { position: relative; display: flex; align-items: center; min-height: 2.35rem; padding: .5rem .7rem; margin: .1rem 0; border-radius: .55rem; color: #333840; text-decoration: none; font-weight: 600; font-size: .9rem; transition: background .12s ease, color .12s ease, transform .12s ease; }
        nav a:hover { background: var(--sidebar-hover); color: #111; }
        nav a[aria-current="page"] { background: #fff8db; color: #111; font-weight: 750; }
        nav a[aria-current="page"]::before { content: ""; position: absolute; left: 0; top: .42rem; bottom: .42rem; width: 3px; border-radius: 999px; background: var(--iowa-gold); }

        main { padding: 2rem 2.25rem 3rem; min-width: 0; background: #f6f7f9; }
        .page-header { display: flex; gap: 1rem; justify-content: space-between; align-items: flex-start; margin-bottom: 1.35rem; }
        .page-header h1 { margin: 0; }
        .page-header p { margin: .35rem 0 0; color: var(--muted); }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.35rem; box-shadow: var(--shadow-sm); }
        .card h2:first-child, .card h3:first-child { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(12.5rem, 1fr)); gap: .9rem; }
        .stat { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.05rem 1.1rem; box-shadow: var(--shadow-sm); min-height: 7.4rem; display: flex; flex-direction: column; justify-content: space-between; }
        .stat strong { display: block; font-size: 1.75rem; line-height: 1; color: #111; letter-spacing: -.025em; }
        .stat span { color: var(--muted); font-size: .9rem; max-width: 12rem; }

        .actions { display: flex; gap: .55rem; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        button, .button { display: inline-flex; align-items: center; justify-content: center; min-height: 2.55rem; padding: .56rem .9rem; background: #111; color: #fff; border: 1px solid #111; border-radius: .55rem; font-weight: 700; text-decoration: none; cursor: pointer; font: inherit; font-size: .92rem; line-height: 1.2; box-shadow: 0 1px 1px rgba(0,0,0,.04); transition: background .12s ease, border-color .12s ease, box-shadow .12s ease, transform .12s ease; }
        button.secondary, .button.secondary { background: #fff; color: #25282d; border-color: var(--border-strong); }
        button.small, .button.small { min-height: 2rem; padding: .35rem .6rem; font-size: .85rem; }
        button:hover, .button:hover { background: #2a2a2a; border-color: #2a2a2a; color: #fff; box-shadow: 0 2px 5px rgba(0,0,0,.10); }
        button.secondary:hover, .button.secondary:hover { background: #f4f5f6; color: #111; border-color: #aeb4bd; }

        label { display: block; font-weight: 700; margin: .85rem 0 .3rem; color: #262a30; font-size: .9rem; }
        input, select, textarea { width: 100%; padding: .65rem .72rem; border: 1px solid #aeb4bd; border-radius: .5rem; font: inherit; background: #fff; color: #181a1f; min-height: 2.55rem; }
        input[type="checkbox"], input[type="radio"] { width: auto; min-height: auto; }
        textarea { min-height: 7rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: .75rem 1rem; }
        .form-actions { margin-top: 1rem; }

        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: .75rem .75rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { background: #fafbfc; font-size: .78rem; color: #59606a; text-transform: uppercase; letter-spacing: .035em; font-weight: 800; }
        tbody tr:hover { background: #fbfcfd; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-md); background: #fff; box-shadow: var(--shadow-sm); }

        .status { display: inline-flex; align-items: center; border-radius: 999px; padding: .18rem .58rem; font-size: .77rem; font-weight: 750; line-height: 1.35; }
        .status-success { background: #e8f5ec; color: #175c2e; }
        .status-danger { background: #fdeaea; color: #8a1717; }
        .status-warning { background: #fff4d6; color: #705200; }
        .status-neutral { background: #eff1f3; color: #4a4f57; }

        .notice { border: 1px solid #bdd9f0; border-left: 4px solid #005ea8; border-radius: .55rem; background: #f2f8fd; padding: .85rem 1rem; margin-bottom: 1rem; }
        .error { border: 1px solid #efc0c0; border-left: 4px solid #b50909; border-radius: .55rem; background: #fff4f4; padding: .85rem 1rem; margin-bottom: 1rem; }
        .success-box { border: 1px solid #bee0c7; border-left: 4px solid #2e8540; border-radius: .55rem; background: #f0f9f2; padding: .85rem 1rem; margin-bottom: 1rem; }
        .muted { color: var(--muted); }
        .checklist { display: grid; gap: .7rem; }
        .check-item { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center; padding: .85rem; border: 1px solid var(--border); border-radius: .6rem; background: #fff; }
        .criteria { margin: 0; padding-left: 1.25rem; }
        details { border: 1px solid var(--border); border-radius: .6rem; padding: .65rem .8rem; background: #fff; }
        summary { cursor: pointer; font-weight: 700; }
        a { color: #005ea8; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }

        @media (max-width: 980px) {
            .app-shell { grid-template-columns: 13.75rem minmax(0, 1fr); }
            main { padding: 1.5rem; }
            nav a { font-size: .86rem; }
        }
        @media (max-width: 760px) {
            .header-inner { padding: .72rem 1rem; }
            .brand { white-space: normal; line-height: 1.2; }
            .brand .iowa { display: none; }
            .header-meta { display: none; }
            .app-shell { grid-template-columns: 1fr; min-height: auto; }
            nav { position: static; height: auto; border-right: 0; border-bottom: 1px solid var(--border); display: flex; gap: .25rem; overflow-x: auto; padding: .7rem .75rem; }
            .nav-section { display: flex; gap: .25rem; margin: 0; flex: 0 0 auto; }
            .nav-section-label { display: none; }
            nav a { white-space: nowrap; margin: 0; min-height: 2.2rem; }
            nav a[aria-current="page"]::before { display: none; }
            main { padding: 1.15rem; }
            .page-header { flex-direction: column; margin-bottom: 1rem; }
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .stat { min-height: 6.8rem; }
        }
        @media (max-width: 480px) {
            .grid { grid-template-columns: 1fr; }
            .actions { align-items: stretch; }
            .actions > a, .actions > button, .actions > form { width: 100%; }
            .actions form button { width: 100%; }
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
            return self::navGroup('Awards', [
                ['/portal', 'My Awards'],
            ]);
        }

        if (in_array($role, ['program_coordinator', 'department_chair'], true)) {
            return '<nav aria-label="Primary">'
                . self::navGroup('Overview', [
                    ['/dashboard', 'Dashboard'],
                ])
                . self::navGroup('Review', [
                    ['/review', 'Program Review'],
                    ['/thank-yous/review', 'Thank-you Review'],
                ])
                . '</nav>';
        }

        $testMail = (Env::get('APP_ENV', 'development') ?? 'development') !== 'production'
            ? [['/admin/test-mail', 'Testing Mail']]
            : [];

        return '<nav aria-label="Primary">'
            . self::navGroup('Overview', [
                ['/dashboard', 'Dashboard'],
            ])
            . self::navGroup('Cycle', [
                ['/admin/cycles', 'Cycles'],
                ['/admin/cycle-report', 'Cycle Report'],
                ['/admin/planning', 'Annual Planning'],
                ['/admin/history-imports', 'History Import'],
            ])
            . self::navGroup('Scholarships', [
                ['/admin/scholarships', 'Scholarships'],
                ['/admin/renewals', 'Renewals'],
                ['/admin/allocations', 'Allocations'],
                ['/admin/applicants', 'Applicants'],
                ['/admin/enrollment', 'Enrollment'],
            ])
            . self::navGroup('Review', [
                ['/review', 'Program Review'],
                ['/admin/reviewers', 'Reviewers'],
                ['/admin/rubrics', 'Rubrics'],
                ['/dean/recommendations', 'Dean Review'],
                ['/dean/reallocation', 'Reallocation'],
                ['/dean/verification', 'Verification'],
            ])
            . self::navGroup('Recipients', [
                ['/admin/distribution-requests', 'Distribution Requests'],
                ['/admin/thank-yous', 'Thank-you Letters'],
                ['/admin/thank-you-reminders', 'Thank-you Reminders'],
                ['/thank-yous/review', 'Thank-you Review'],
                ['/admin/thank-you-reviewers', 'Thank-you Reviewers'],
            ])
            . self::navGroup('Communications', [
                ['/notifications/ready', 'Notify'],
                ['/notifications/history', 'Notification History'],
                ['/admin/templates', 'Templates'],
            ])
            . self::navGroup('Administration', array_merge([
                ['/admin/organization', 'Programs'],
                ['/admin/users', 'Users'],
            ], $testMail))
            . '</nav>';
    }

    /**
     * @param array<int, array{0:string,1:string}> $items
     */
    private static function navGroup(string $label, array $items): string
    {
        $html = '<div class="nav-section"><span class="nav-section-label">' . self::e($label) . '</span>';

        foreach ($items as [$href, $text]) {
            $html .= self::navItem($href, $text);
        }

        return $html . '</div>';
    }

    private static function navItem(string $href, string $label): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim($href, '/');
        $active = $path === $href || ($base !== '' && str_starts_with($path, $base . '/'));
        $current = $active ? ' aria-current="page"' : '';

        return '<a href="' . self::e($href) . '"' . $current . '>' . self::e($label) . '</a>';
    }
}
