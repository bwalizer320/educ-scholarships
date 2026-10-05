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

        $logoutHtml = $userName
            ? '<form method="post" action="/logout">' . self::csrfField()
                . '<button class="header-logout" type="submit" title="Sign out">'
                . self::icon('logout')
                . '<span class="sr-only">Sign out</span></button></form>'
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
            font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #17191c;
            background: #f5f6f8;
            --iowa-gold: #ffcd00;
            --ink: #111214;
            --text: #24272c;
            --muted: #69707a;
            --subtle: #8b929c;
            --border: #e1e4e8;
            --border-strong: #c8cdd4;
            --focus: #005ea8;
            --surface: #ffffff;
            --surface-soft: #f8f9fb;
            --surface-warm: #fff9df;
            --sidebar: #111214;
            --sidebar-hover: #1d2024;
            --shadow-sm: 0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .03);
            --shadow-md: 0 8px 24px rgba(16, 24, 40, .08);
            --radius-sm: .55rem;
            --radius-md: .8rem;
            --radius-lg: 1rem;
        }

        * { box-sizing: border-box; }
        html { background: #f5f6f8; }
        body { margin: 0; color: var(--text); background: #f5f6f8; font-size: .95rem; line-height: 1.5; }
        h1, h2, h3 { color: var(--ink); letter-spacing: -.025em; }
        h1 { font-size: clamp(1.8rem, 2.1vw, 2.35rem); line-height: 1.08; }
        h2 { font-size: 1.15rem; }
        h3 { font-size: 1rem; }
        a { color: #005ea8; }

        .skip { position: absolute; left: -9999px; top: 0; background: #fff; color: #000; padding: .75rem 1rem; z-index: 100; border-radius: .5rem; }
        .skip:focus { left: .75rem; top: .75rem; }

        header { height: 60px; background: #050505; color: #fff; border-bottom: 3px solid var(--iowa-gold); position: sticky; top: 0; z-index: 30; }
        .header-inner { height: 100%; width: 100%; margin: 0; padding: 0 1.15rem; display: flex; gap: 1rem; align-items: center; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: .7rem; min-width: 0; font-size: .92rem; font-weight: 720; white-space: nowrap; }
        .brand .iowa { color: var(--iowa-gold); letter-spacing: .08em; font-weight: 850; }
        .brand-divider { width: 1px; height: 20px; background: #3e4146; }
        .brand-product { color: #fff; overflow: hidden; text-overflow: ellipsis; }
        .header-meta { display: flex; gap: .75rem; align-items: center; }
        .user-block { display: flex; flex-direction: column; text-align: right; font-size: .79rem; line-height: 1.18; }
        .user-block strong { font-weight: 700; }
        .user-block span { color: #b8bec6; font-size: .7rem; margin-top: .1rem; }
        .cycle-pill { display: inline-flex; align-items: center; gap: .35rem; border: 1px solid #484c52; background: #15171a; border-radius: 999px; padding: .25rem .58rem; font-size: .72rem; font-weight: 750; color: #fff; }
        .cycle-pill::before { content: ""; width: .42rem; height: .42rem; border-radius: 50%; background: var(--iowa-gold); }
        .header-logout { width: 2.15rem; height: 2.15rem; min-height: 0; padding: 0; border-radius: .6rem; border: 1px solid #41454b; background: #15171a; color: #fff; box-shadow: none; }
        .header-logout:hover { background: #24272b; border-color: #5a5f66; }
        .header-logout svg { width: 1rem; height: 1rem; }
        .sr-only { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; overflow: hidden !important; clip: rect(0, 0, 0, 0) !important; white-space: nowrap !important; border: 0 !important; }

        .app-shell { width: 100%; margin: 0; display: grid; grid-template-columns: 13.5rem minmax(0, 1fr); min-height: calc(100vh - 60px); }
        .app-shell.public-shell { grid-template-columns: 1fr; max-width: 52rem; margin: 0 auto; min-height: auto; }

        nav { background: var(--sidebar); border-right: 1px solid #22252a; padding: .8rem .6rem 1.25rem; position: sticky; top: 60px; height: calc(100vh - 60px); overflow-y: auto; scrollbar-width: thin; scrollbar-color: #3b3f45 transparent; }
        .nav-home,
        .nav-disclosure > summary { position: relative; display: flex; align-items: center; gap: .62rem; min-height: 2.35rem; padding: .5rem .62rem; margin: .08rem 0; border-radius: .58rem; color: #c9ced5; font-weight: 680; font-size: .84rem; line-height: 1.2; transition: background .12s ease, color .12s ease; }
        .nav-home { text-decoration: none; }
        .nav-home:hover,
        .nav-disclosure > summary:hover { background: var(--sidebar-hover); color: #fff; }
        .nav-home[aria-current="page"] { background: #272a2f; color: #fff; box-shadow: inset 3px 0 0 var(--iowa-gold); }
        .nav-home[aria-current="page"] .nav-icon,
        .nav-home:hover .nav-icon,
        .nav-disclosure[open] > summary .nav-icon,
        .nav-disclosure > summary:hover .nav-icon { color: var(--iowa-gold); }
        .nav-disclosure { margin: .1rem 0; padding: 0; border: 0; border-radius: 0; background: transparent; }
        .nav-disclosure > summary { list-style: none; cursor: pointer; user-select: none; }
        .nav-disclosure > summary::-webkit-details-marker { display: none; }
        .nav-disclosure[open] > summary { background: #1a1d21; color: #fff; }
        .nav-disclosure.is-current > summary { color: #fff; }
        .nav-icon { width: 1rem; height: 1rem; flex: 0 0 1rem; color: #8f969f; }
        .nav-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .nav-chevron { width: .8rem; height: .8rem; flex: 0 0 .8rem; color: #727982; transition: transform .15s ease; }
        .nav-disclosure[open] > summary .nav-chevron { transform: rotate(90deg); color: #aab0b8; }
        .nav-children { position: relative; margin: .15rem 0 .35rem 1.13rem; padding: .08rem 0 .08rem .7rem; border-left: 1px solid #34383e; }
        .nav-child { display: block; min-height: 1.95rem; padding: .38rem .55rem; margin: .03rem 0; border-radius: .45rem; color: #9da4ad; text-decoration: none; font-size: .77rem; font-weight: 560; line-height: 1.25; }
        .nav-child:hover { background: #1b1e22; color: #fff; }
        .nav-child[aria-current="page"] { background: #23262b; color: #fff; font-weight: 700; }
        .nav-child[aria-current="page"]::before { content: ""; position: absolute; left: -.5px; width: 2px; height: 1.1rem; margin-top: .06rem; background: var(--iowa-gold); border-radius: 999px; }

        main { min-width: 0; background: #f5f6f8; padding: 1.75rem 2rem 3rem; }
        main > * { max-width: 92rem; }
        .page-header { display: flex; gap: 1rem; justify-content: space-between; align-items: flex-start; margin-bottom: 1.2rem; }
        .page-header h1 { margin: 0; }
        .page-header p { margin: .35rem 0 0; color: var(--muted); }
        .eyebrow { display: flex; align-items: center; gap: .45rem; color: var(--muted); font-size: .78rem; font-weight: 750; margin-bottom: .45rem; }
        .eyebrow-dot { width: .45rem; height: .45rem; border-radius: 50%; background: var(--iowa-gold); }

        .card, .module { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow-sm); }
        .card h2:first-child, .card h3:first-child, .module h2:first-child, .module h3:first-child { margin-top: 0; }
        .module-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
        .module-header h2 { margin: 0; }
        .module-header p { margin: .25rem 0 0; color: var(--muted); font-size: .86rem; }
        .module-link { font-size: .82rem; font-weight: 700; text-decoration: none; white-space: nowrap; }

        .grid, .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11.5rem, 1fr)); gap: .8rem; }
        .stat, .metric-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; box-shadow: var(--shadow-sm); min-height: 7.2rem; }
        .metric-card { display: grid; grid-template-columns: auto 1fr; gap: .85rem; align-items: start; }
        .metric-icon { width: 2.25rem; height: 2.25rem; border-radius: .7rem; background: #f2f3f5; display: grid; place-items: center; color: #34383e; }
        .metric-icon svg { width: 1.08rem; height: 1.08rem; }
        .metric-label { display: block; color: var(--muted); font-size: .78rem; font-weight: 650; }
        .metric-value { display: block; margin-top: .22rem; color: #111; font-size: 1.75rem; line-height: 1; font-weight: 780; letter-spacing: -.035em; }
        .metric-meta { display: block; margin-top: .5rem; color: var(--subtle); font-size: .72rem; }

        .dashboard-layout { display: grid; grid-template-columns: minmax(0, 1.75fr) minmax(18rem, .75fr); gap: .9rem; margin-top: .9rem; align-items: start; }
        .dashboard-stack { display: grid; gap: .9rem; }
        .workflow-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .65rem; }
        .workflow-step { display: flex; gap: .72rem; align-items: flex-start; padding: .85rem; border: 1px solid var(--border); border-radius: .75rem; background: #fff; text-decoration: none; color: var(--text); min-height: 5.1rem; transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease; }
        .workflow-step:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); border-color: #c9ced6; color: var(--text); }
        .step-number { width: 1.75rem; height: 1.75rem; flex: 0 0 1.75rem; border-radius: .55rem; display: grid; place-items: center; background: #111; color: var(--iowa-gold); font-size: .72rem; font-weight: 800; }
        .workflow-step strong { display: block; font-size: .86rem; color: #17191c; }
        .workflow-step span:last-child { display: block; margin-top: .18rem; color: var(--muted); font-size: .72rem; line-height: 1.35; }
        .workflow-step.primary { background: var(--surface-warm); border-color: #ead781; }
        .workflow-step.primary .step-number { background: var(--iowa-gold); color: #111; }

        .attention-list { display: grid; gap: .55rem; }
        .attention-item { display: grid; grid-template-columns: auto 1fr auto; gap: .7rem; align-items: center; padding: .72rem; border: 1px solid var(--border); border-radius: .7rem; text-decoration: none; color: var(--text); background: #fff; }
        .attention-item:hover { border-color: #c8cdd4; background: #fafbfc; color: var(--text); }
        .attention-icon { width: 2rem; height: 2rem; border-radius: .6rem; display: grid; place-items: center; background: #f2f3f5; color: #41464d; }
        .attention-icon svg { width: 1rem; height: 1rem; }
        .attention-copy strong { display: block; font-size: .82rem; color: #202328; }
        .attention-copy span { display: block; color: var(--muted); font-size: .7rem; margin-top: .08rem; }
        .count-badge { min-width: 1.7rem; height: 1.7rem; padding: 0 .38rem; border-radius: 999px; display: inline-grid; place-items: center; background: #111; color: #fff; font-size: .72rem; font-weight: 800; }
        .count-badge.clear { background: #edf6ef; color: #176033; }

        .quick-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
        .quick-link { display: flex; align-items: center; gap: .55rem; min-height: 2.65rem; padding: .65rem .72rem; border: 1px solid var(--border); border-radius: .65rem; background: #fff; color: #262a30; text-decoration: none; font-size: .8rem; font-weight: 700; }
        .quick-link:hover { background: #f8f9fa; border-color: #c9ced6; color: #111; }
        .quick-link svg { width: 1rem; height: 1rem; color: #666d76; }

        .actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        button, .button { display: inline-flex; align-items: center; justify-content: center; gap: .42rem; min-height: 2.42rem; padding: .52rem .82rem; background: #111; color: #fff; border: 1px solid #111; border-radius: .58rem; font-weight: 720; text-decoration: none; cursor: pointer; font: inherit; font-size: .84rem; line-height: 1.2; box-shadow: 0 1px 1px rgba(0,0,0,.04); transition: background .12s ease, border-color .12s ease, box-shadow .12s ease; }
        button svg, .button svg { width: .95rem; height: .95rem; flex: 0 0 .95rem; }
        button.secondary, .button.secondary { background: #fff; color: #25282d; border-color: var(--border-strong); }
        button.small, .button.small { min-height: 2rem; padding: .35rem .6rem; font-size: .78rem; }
        button:hover, .button:hover { background: #2a2a2a; border-color: #2a2a2a; color: #fff; box-shadow: 0 2px 5px rgba(0,0,0,.10); }
        button.secondary:hover, .button.secondary:hover { background: #f4f5f6; color: #111; border-color: #aeb4bd; }

        label { display: block; font-weight: 700; margin: .8rem 0 .28rem; color: #262a30; font-size: .84rem; }
        input, select, textarea { width: 100%; padding: .62rem .7rem; border: 1px solid #aeb4bd; border-radius: .52rem; font: inherit; background: #fff; color: #181a1f; min-height: 2.45rem; }
        input[type="checkbox"], input[type="radio"] { width: auto; min-height: auto; }
        textarea { min-height: 7rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: .75rem 1rem; }
        .form-actions { margin-top: 1rem; }

        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: .72rem .72rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { background: #fafbfc; font-size: .7rem; color: #59606a; text-transform: uppercase; letter-spacing: .055em; font-weight: 800; }
        tbody tr:hover { background: #fbfcfd; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-md); background: #fff; box-shadow: var(--shadow-sm); }

        .status { display: inline-flex; align-items: center; border-radius: 999px; padding: .18rem .52rem; font-size: .71rem; font-weight: 760; line-height: 1.35; }
        .status-success { background: #e8f5ec; color: #175c2e; }
        .status-danger { background: #fdeaea; color: #8a1717; }
        .status-warning { background: #fff4d6; color: #705200; }
        .status-neutral { background: #eff1f3; color: #4a4f57; }

        .notice { border: 1px solid #bdd9f0; border-left: 4px solid #005ea8; border-radius: .55rem; background: #f2f8fd; padding: .8rem .95rem; margin-bottom: 1rem; }
        .error { border: 1px solid #efc0c0; border-left: 4px solid #b50909; border-radius: .55rem; background: #fff4f4; padding: .8rem .95rem; margin-bottom: 1rem; }
        .success-box { border: 1px solid #bee0c7; border-left: 4px solid #2e8540; border-radius: .55rem; background: #f0f9f2; padding: .8rem .95rem; margin-bottom: 1rem; }
        .muted { color: var(--muted); }
        .checklist { display: grid; gap: .65rem; }
        .check-item { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center; padding: .8rem; border: 1px solid var(--border); border-radius: .6rem; background: #fff; }
        .criteria { margin: 0; padding-left: 1.25rem; }
        details { border: 1px solid var(--border); border-radius: .6rem; padding: .62rem .76rem; background: #fff; }
        summary { cursor: pointer; font-weight: 700; }

        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }

        @media (max-width: 1120px) {
            .dashboard-layout { grid-template-columns: 1fr; }
            .workflow-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .app-shell { grid-template-columns: 11.75rem minmax(0, 1fr); }
            main { padding: 1.35rem; }
            nav a { font-size: .78rem; }
        }
        @media (max-width: 720px) {
            header { position: static; }
            .header-inner { padding: 0 .85rem; }
            .brand-product { max-width: 16rem; }
            .brand .iowa, .brand-divider { display: none; }
            .cycle-pill, .user-block { display: none; }
            .app-shell { grid-template-columns: 1fr; min-height: auto; }
            nav { position: static; height: auto; border-right: 0; border-bottom: 1px solid #22252a; padding: .55rem .65rem .7rem; overflow: visible; }
            .nav-home, .nav-disclosure > summary { min-height: 2.3rem; }
            .nav-children { margin-left: 1.15rem; }
            main { padding: 1rem; }
            .page-header { flex-direction: column; margin-bottom: 1rem; }
            .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .workflow-grid { grid-template-columns: 1fr; }
            .quick-links { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .metric-grid { grid-template-columns: 1fr; }
            .actions { align-items: stretch; }
            .actions > a, .actions > button, .actions > form { width: 100%; }
            .actions form button { width: 100%; }
            .brand-product { max-width: 12rem; }
        }
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to main content</a>
<header>
    <div class="header-inner">
        <div class="brand"><span class="iowa">IOWA</span><span class="brand-divider" aria-hidden="true"></span><span class="brand-product">College of Education · Scholarships</span></div>
        <div class="header-meta">{$cycleHtml}{$userHtml}{$logoutHtml}</div>
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

    public static function icon(string $name, string $class = ''): string
    {
        $paths = match ($name) {
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'calendar' => '<path d="M6 2v4M18 2v4M3 9h18"/><rect x="3" y="4" width="18" height="17" rx="2"/>',
            'report' => '<path d="M6 2h9l4 4v16H6z"/><path d="M14 2v5h5M9 13h6M9 17h6"/>',
            'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
            'planning' => '<path d="M4 19V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14"/><path d="M8 7h8M8 11h8M8 15h5M3 21h18"/>',
            'award' => '<circle cx="12" cy="8" r="5"/><path d="M8.5 12.5 7 22l5-3 5 3-1.5-9.5"/>',
            'renew' => '<path d="M20 7h-5V2M4 17h5v5"/><path d="M18.5 5.5A8 8 0 0 0 5 7M5.5 18.5A8 8 0 0 0 19 17"/>',
            'allocation' => '<path d="M12 3v18M3 8h18M5 8v4a3 3 0 0 0 6 0V8M13 8v4a3 3 0 0 0 6 0V8"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'applicant' => '<circle cx="9" cy="8" r="4"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M19 8v6M16 11h6"/>',
            'enrollment' => '<path d="m3 10 9-5 9 5-9 5z"/><path d="M7 12.5V17c2.8 2 7.2 2 10 0v-4.5M21 10v6"/>',
            'review' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'rubric' => '<path d="M9 5h10M9 12h10M9 19h10"/><path d="m3 5 1 1 2-2M3 12l1 1 2-2M3 19l1 1 2-2"/>',
            'dean' => '<path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6"/>',
            'verify' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'template' => '<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/>',
            'program' => '<path d="M4 21V4h7v17M13 21V9h7v12M2 21h20"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06-2.83 2.83-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21h-4v-.17a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06-2.83-2.83.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3v-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06 2.83-2.83.06.06A1.65 1.65 0 0 0 8.92 4a1.65 1.65 0 0 0 1-1.51V2h4v.49A1.65 1.65 0 0 0 14.92 4a1.65 1.65 0 0 0 1.82-.33l.06-.06 2.83 2.83-.06.06A1.65 1.65 0 0 0 19.4 9c.12.61.61 1.1 1.22 1.22H21v4h-.38A1.65 1.65 0 0 0 19.4 15z"/>',
            'logout' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'chevron' => '<path d="m9 18 6-6-6-6"/>',
            default => '<circle cx="12" cy="12" r="8"/>',
        };

        $classAttr = $class !== '' ? ' class="' . self::e($class) . '"' : '';

        return '<svg' . $classAttr . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    private static function nav(?string $role): string
    {
        if ($role === 'student') {
            return '<nav aria-label="Recipient navigation">'
                . self::navRootItem('/portal', 'My Awards', 'award')
                . '</nav>';
        }

        if (in_array($role, ['program_coordinator', 'department_chair'], true)) {
            return '<nav aria-label="Primary">'
                . self::navRootItem('/dashboard', 'Dashboard', 'dashboard')
                . self::navDisclosure('Review', 'review', [
                    ['/review', 'Program Review'],
                    ['/thank-yous/review', 'Thank-you Review'],
                ])
                . '</nav>';
        }

        $adminItems = [
            ['/admin/organization', 'Programs'],
            ['/admin/users', 'Users'],
            ['/notifications/ready', 'Notify'],
            ['/notifications/history', 'Notification History'],
            ['/admin/templates', 'Templates'],
        ];

        if ((Env::get('APP_ENV', 'development') ?? 'development') !== 'production') {
            $adminItems[] = ['/admin/test-mail', 'Testing Mail'];
        }

        return '<nav aria-label="Primary">'
            . self::navRootItem('/dashboard', 'Dashboard', 'dashboard')
            . self::navDisclosure('Cycle', 'calendar', [
                ['/admin/cycles', 'Cycles'],
                ['/admin/planning', 'Planning'],
                ['/admin/cycle-report', 'Cycle Report'],
                ['/admin/history-imports', 'History Import'],
            ])
            . self::navDisclosure('Awards', 'award', [
                ['/admin/scholarships', 'Scholarships'],
                ['/admin/renewals', 'Renewals'],
                ['/admin/allocations', 'Allocations'],
                ['/admin/applicants', 'Applicants'],
                ['/admin/enrollment', 'Enrollment'],
            ])
            . self::navDisclosure('Review', 'review', [
                ['/review', 'Program Review'],
                ['/admin/reviewers', 'Reviewers'],
                ['/admin/rubrics', 'Rubrics'],
                ['/dean/recommendations', 'Dean Review'],
                ['/dean/reallocation', 'Reallocation'],
                ['/dean/verification', 'Verification'],
            ])
            . self::navDisclosure('Recipients', 'users', [
                ['/admin/distribution-requests', 'Distribution'],
                ['/admin/thank-yous', 'Thank-you Letters'],
                ['/admin/thank-you-reminders', 'Reminders'],
                ['/thank-yous/review', 'Thank-you Review'],
                ['/admin/thank-you-reviewers', 'Thank-you Reviewers'],
            ])
            . self::navDisclosure('Admin', 'settings', $adminItems)
            . '</nav>';
    }

    private static function navRootItem(string $href, string $label, string $icon): string
    {
        $current = self::navPathIsActive($href) ? ' aria-current="page"' : '';

        return '<a class="nav-home" href="' . self::e($href) . '"' . $current . '>'
            . self::icon($icon, 'nav-icon')
            . '<span class="nav-label">' . self::e($label) . '</span>'
            . '</a>';
    }

    /**
     * @param array<int, array{0:string,1:string}> $items
     */
    private static function navDisclosure(string $label, string $icon, array $items): string
    {
        $isCurrent = false;
        foreach ($items as [$href]) {
            if (self::navPathIsActive($href)) {
                $isCurrent = true;
                break;
            }
        }

        $open = $isCurrent ? ' open' : '';
        $currentClass = $isCurrent ? ' is-current' : '';
        $html = '<details class="nav-disclosure' . $currentClass . '" name="primary-nav"' . $open . '>'
            . '<summary>'
            . self::icon($icon, 'nav-icon')
            . '<span class="nav-label">' . self::e($label) . '</span>'
            . self::icon('chevron', 'nav-chevron')
            . '</summary>'
            . '<div class="nav-children">';

        foreach ($items as [$href, $text]) {
            $html .= self::navChildItem($href, $text);
        }

        return $html . '</div></details>';
    }

    private static function navChildItem(string $href, string $label): string
    {
        $current = self::navPathIsActive($href) ? ' aria-current="page"' : '';

        return '<a class="nav-child" href="' . self::e($href) . '"' . $current . '>'
            . self::e($label)
            . '</a>';
    }

    private static function navPathIsActive(string $href): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim($href, '/');

        return $path === $href || ($base !== '' && str_starts_with($path, $base . '/'));
    }
}
