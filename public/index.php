<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Services\Recipients\UicaAccessService;
use App\Controllers\Admin\ThankYouAdminController;
use App\Controllers\ThankYouReviewController;
use App\Policies\ProgramScopePolicy;
use App\Controllers\Admin\UserController;
use App\Controllers\Admin\RecipientAdminController;
use App\Controllers\ReviewController;
use App\Controllers\RecipientController;
use App\Controllers\ActivationController;
use App\Controllers\Admin\ApplicantController;
use App\Controllers\Admin\CycleController;
use App\Controllers\Admin\CycleReportController;
use App\Controllers\Admin\DeanReviewController;
use App\Controllers\Admin\EnrollmentController;
use App\Controllers\Admin\HistoricalImportController;
use App\Controllers\Admin\PlanningController;
use App\Controllers\Admin\ReallocationController;
use App\Controllers\Admin\ScholarshipController;
use App\Controllers\Admin\NotificationController;
use App\Controllers\Admin\OrganizationController;
use App\Controllers\Admin\TemplateController;
use App\Controllers\Admin\TestMailController;
use App\Controllers\Admin\ReviewSetupController;
use App\Database\Connection;
use App\Http\Csrf;
use App\Http\Router;
use App\Support\Flash;
use App\Support\View;

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store, private');

$pdo = Connection::get();
$auth = new AuthService($pdo);
$router = new Router();

$activationController = new ActivationController($pdo, $auth);
$recipientController = new RecipientController($pdo, $auth);
$thankYouReviewController = new ThankYouReviewController($pdo, $auth, new UicaAccessService($pdo));

$cycleController = new CycleController($pdo, $auth);
$cycleReportController = new CycleReportController($pdo, $auth);
$deanReviewController = new DeanReviewController($pdo, $auth);
$enrollmentController = new EnrollmentController($pdo, $auth);
$historicalImportController = new HistoricalImportController($pdo, $auth);
$planningController = new PlanningController($pdo, $auth);
$reallocationController = new ReallocationController($pdo, $auth);
$scholarshipController = new ScholarshipController($pdo, $auth);
$notificationController = new NotificationController($pdo, $auth);
$organizationController = new OrganizationController($pdo, $auth);
$templateController = new TemplateController($pdo, $auth);
$testMailController = new TestMailController($pdo, $auth);
$reviewSetupController = new ReviewSetupController($pdo, $auth);
$recipientAdminController = new RecipientAdminController($pdo, $auth);
$thankYouAdminController = new ThankYouAdminController($pdo, $auth);
$applicantController = new ApplicantController($pdo, $auth);
$userController = new UserController($pdo, $auth);
$reviewController = new ReviewController($pdo, $auth, new ProgramScopePolicy($pdo));

$router->get('/healthz', static function () use ($pdo): string {
    header('Content-Type: text/plain; charset=utf-8');

    try {
        $pdo->query('SELECT 1')->fetchColumn();
        http_response_code(200);
        return 'ok';
    } catch (\Throwable) {
        http_response_code(503);
        return 'unavailable';
    }
});

$router->get('/', static function () use ($auth): never {
    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }

    header('Location: ' . ($auth->personType() === 'student' ? '/portal' : '/dashboard'));
    exit;
});

$router->get('/login', static function () use ($auth): string {
    if ($auth->check()) {
        header('Location: ' . ($auth->personType() === 'student' ? '/portal' : '/dashboard'));
        exit;
    }

    $body = Flash::render()
        . '<section class="card" aria-labelledby="login-title">'
        . '<h1 id="login-title">Sign in</h1>'
        . '<p>Use the local account during testing. Production authentication can be switched to HawkID/SSO by configuration.</p>'
        . '<form method="post" action="/login">'
        . View::csrfField()
        . '<label for="email">Email</label>'
        . '<input id="email" name="email" type="email" autocomplete="username" required>'
        . '<label for="password">Password</label>'
        . '<input id="password" name="password" type="password" autocomplete="current-password" required>'
        . '<div class="form-actions"><button type="submit">Sign in</button></div>'
        . '</form></section>';

    return View::layout('Sign in', $body);
});

$router->post('/login', static function () use ($auth): never {
    try {
        Csrf::assertValid($_POST['_csrf'] ?? null);

        if (!$auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            Flash::error('Sign-in failed. Check the email and password.');
            header('Location: /login');
            exit;
        }

        header('Location: ' . ($auth->personType() === 'student' ? '/portal' : '/dashboard'));
        exit;
    } catch (\Throwable $e) {
        Flash::error($e->getMessage());
        header('Location: /login');
        exit;
    }
});

$router->post('/logout', static function () use ($auth): never {
    Csrf::assertValid($_POST['_csrf'] ?? null);
    $auth->logout();
    header('Location: /login');
    exit;
});

$router->get('/dashboard', static function () use ($auth, $pdo): string {
    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }
    if ($auth->personType() === 'student') {
        header('Location: /portal');
        exit;
    }

    if (in_array($auth->staffRole(), ['program_coordinator', 'department_chair'], true)) {
        header('Location: /review');
        exit;
    }

    $cycle = $pdo->query(
        'SELECT * FROM academic_cycles WHERE is_current = 1 LIMIT 1'
    )->fetch() ?: null;

    $cycleId = $cycle ? (int) $cycle['id'] : 0;
    $scholarships = 0;
    $renewals = 0;
    $applicants = 0;
    $reviewUnits = 0;
    $unresolvedMappings = (int) $pdo->query(
        "SELECT COUNT(*) FROM program_mapping_queue WHERE status = 'unresolved'"
    )->fetchColumn();

    if ($cycleId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM cycle_scholarships WHERE cycle_id = ?');
        $stmt->execute([$cycleId]);
        $scholarships = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM renewal_candidates
             WHERE cycle_id = ? AND status IN ('pending_amount','pending_review','hold')"
        );
        $stmt->execute([$cycleId]);
        $renewals = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE cycle_id = ? AND active = 1');
        $stmt->execute([$cycleId]);
        $applicants = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM cycle_review_units
             WHERE cycle_id = ? AND status NOT IN ('submitted','closed','resubmitted')"
        );
        $stmt->execute([$cycleId]);
        $reviewUnits = (int) $stmt->fetchColumn();
    }

    $cycleLabel = $cycle ? View::e($cycle['label']) : 'No current cycle';
    $cycleStatus = $cycle ? View::status($cycle['status']) : View::status('setup');
    $token = View::csrfField();

    $setupHref = $cycle
        ? '/admin/cycles/' . $cycleId . '/setup'
        : '/admin/cycles';
    $setupLabel = $cycle ? 'Continue cycle setup' : 'Create academic cycle';

    $renewalBadgeClass = $renewals === 0 ? 'count-badge clear' : 'count-badge';
    $reviewBadgeClass = $reviewUnits === 0 ? 'count-badge clear' : 'count-badge';
    $mappingBadgeClass = $unresolvedMappings === 0 ? 'count-badge clear' : 'count-badge';

    $body = Flash::render()
        . '<div class="page-header">'
        . '<div><div class="eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span>' . $cycleLabel . ' academic cycle · ' . $cycleStatus . '</div>'
        . '<h1>Scholarship Manager</h1>'
        . '<p>Manage the scholarship cycle from planning through review, awarding, and notification.</p></div>'
        . '<div class="actions"><a class="button" href="' . $setupHref . '">' . View::icon('arrow') . $setupLabel . '</a></div>'
        . '</div>'

        . '<div class="metric-grid" aria-label="Current cycle summary">'
        . '<section class="metric-card"><div class="metric-icon">' . View::icon('award') . '</div><div><span class="metric-label">Scholarships</span><strong class="metric-value">' . $scholarships . '</strong><span class="metric-meta">Active in this cycle</span></div></section>'
        . '<section class="metric-card"><div class="metric-icon">' . View::icon('applicant') . '</div><div><span class="metric-label">Applicants</span><strong class="metric-value">' . $applicants . '</strong><span class="metric-meta">Active applications</span></div></section>'
        . '<section class="metric-card"><div class="metric-icon">' . View::icon('renew') . '</div><div><span class="metric-label">Renewals to review</span><strong class="metric-value">' . $renewals . '</strong><span class="metric-meta">Pending, review, or hold</span></div></section>'
        . '<section class="metric-card"><div class="metric-icon">' . View::icon('review') . '</div><div><span class="metric-label">Review units</span><strong class="metric-value">' . $reviewUnits . '</strong><span class="metric-meta">Still outstanding</span></div></section>'
        . '</div>'

        . '<div class="dashboard-layout">'
        . '<section class="module" aria-labelledby="workflow-title">'
        . '<div class="module-header"><div><h2 id="workflow-title">Cycle workflow</h2><p>Move through the core work for ' . $cycleLabel . '.</p></div><a class="module-link" href="/admin/cycles">Manage cycle →</a></div>'
        . '<div class="workflow-grid">'
        . '<a class="workflow-step primary" href="' . $setupHref . '"><span class="step-number">01</span><span><strong>Cycle setup</strong><span>Dates, terms, configuration, and rollover.</span></span></a>'
        . '<a class="workflow-step" href="/admin/planning"><span class="step-number">02</span><span><strong>Annual planning</strong><span>Prepare funds, programs, and award strategy.</span></span></a>'
        . '<a class="workflow-step" href="/admin/renewals"><span class="step-number">03</span><span><strong>Renewals</strong><span>Review continuing recipients and amounts.</span></span></a>'
        . '<a class="workflow-step" href="/admin/allocations"><span class="step-number">04</span><span><strong>Allocations</strong><span>Assign scholarship capacity and funding.</span></span></a>'
        . '<a class="workflow-step" href="/admin/applicants"><span class="step-number">05</span><span><strong>Applicants</strong><span>Import, map, and prepare candidate records.</span></span></a>'
        . '<a class="workflow-step" href="/review"><span class="step-number">06</span><span><strong>Program review</strong><span>Complete program-level scholarship review.</span></span></a>'
        . '</div></section>'

        . '<div class="dashboard-stack">'
        . '<section class="module" aria-labelledby="attention-title">'
        . '<div class="module-header"><div><h2 id="attention-title">Needs attention</h2><p>Items that may block the cycle.</p></div></div>'
        . '<div class="attention-list">'
        . '<a class="attention-item" href="/admin/renewals"><span class="attention-icon">' . View::icon('renew') . '</span><span class="attention-copy"><strong>Renewals</strong><span>Awaiting review or decision</span></span><span class="' . $renewalBadgeClass . '">' . $renewals . '</span></a>'
        . '<a class="attention-item" href="/review"><span class="attention-icon">' . View::icon('review') . '</span><span class="attention-copy"><strong>Program review</strong><span>Outstanding review units</span></span><span class="' . $reviewBadgeClass . '">' . $reviewUnits . '</span></a>'
        . '<a class="attention-item" href="/admin/organization"><span class="attention-icon">' . View::icon('program') . '</span><span class="attention-copy"><strong>Program mappings</strong><span>Unresolved program data</span></span><span class="' . $mappingBadgeClass . '">' . $unresolvedMappings . '</span></a>'
        . '</div></section>'

        . '<section class="module" aria-labelledby="quick-title">'
        . '<div class="module-header"><div><h2 id="quick-title">Quick access</h2><p>Common administrative tools.</p></div></div>'
        . '<div class="quick-links">'
        . '<a class="quick-link" href="/admin/scholarships">' . View::icon('award') . '<span>Scholarships</span></a>'
        . '<a class="quick-link" href="/admin/cycle-report">' . View::icon('report') . '<span>Cycle report</span></a>'
        . '<a class="quick-link" href="/admin/organization">' . View::icon('program') . '<span>Programs</span></a>'
        . '<a class="quick-link" href="/admin/users">' . View::icon('users') . '<span>Users</span></a>'
        . '</div></section>'
        . '</div></div>';

    return View::layout(
        'Dashboard',
        $body,
        $auth->displayName(),
        $auth->staffRole(),
        $cycle['label'] ?? null
    );
});

/* Recipient activation and portal */
$router->get('/activate/{token}', fn(array $p): string => $activationController->form($p));
$router->post('/activate/{token}', fn(array $p): never => $activationController->activate($p));
$router->get('/portal', fn(array $p = []): string => $recipientController->index());
$router->get('/portal/awards/{public}', fn(array $p): string => $recipientController->award($p));
$router->post('/portal/awards/{public}/distribution-request', fn(array $p): never => $recipientController->requestDistribution($p));
$router->post('/portal/awards/{public}/thank-you', fn(array $p): never => $recipientController->submitThankYou($p));
$router->get('/portal/awards/{public}/letter', fn(array $p): never => $recipientController->letter($p));

/* Cycle close and reporting */
$router->get('/admin/cycle-report', fn(array $p = []): string => $cycleReportController->index());
$router->post('/admin/cycle-report/export', fn(array $p = []): never => $cycleReportController->export());
$router->get('/admin/cycle-report/exports/{id}', fn(array $p): never => $cycleReportController->download($p));
$router->post('/admin/cycle-report/complete', fn(array $p = []): never => $cycleReportController->complete());

/* Historical structured imports */
$router->get('/admin/history-imports', fn(array $p = []): string => $historicalImportController->index());
$router->post('/admin/history-imports', fn(array $p = []): never => $historicalImportController->stage());
$router->get('/admin/history-imports/{id}/map', fn(array $p): string => $historicalImportController->mapping($p));
$router->post('/admin/history-imports/{id}/process', fn(array $p): never => $historicalImportController->process($p));

/* Canonical organization */
$router->get('/admin/organization', fn(array $p = []): string => $organizationController->index());

/* Scholarship catalog */
$router->get('/admin/scholarships', fn(array $p = []): string => $scholarshipController->index());
$router->post('/admin/scholarships', fn(array $p = []): never => $scholarshipController->create());
$router->get('/admin/scholarships/{id}', fn(array $p): string => $scholarshipController->detail($p));
$router->post('/admin/scholarships/{id}/criteria', fn(array $p): never => $scholarshipController->addCriterion($p));
$router->post('/admin/scholarships/{id}/criteria/{criterion}/delete', fn(array $p): never => $scholarshipController->deleteCriterion($p));
$router->post('/admin/scholarships/{id}/rules', fn(array $p): never => $scholarshipController->saveRules($p));
$router->post('/admin/scholarships/{id}/add-to-cycle', fn(array $p): never => $scholarshipController->addToCycle($p));
$router->post('/admin/scholarships/{id}/intent-version', fn(array $p): never => $scholarshipController->newVersion($p));

/* Academic cycle setup */
$router->get('/admin/cycles', fn(array $p = []): string => $cycleController->index());
$router->post('/admin/cycles', fn(array $p = []): never => $cycleController->create());
$router->post('/admin/cycles/{id}/current', fn(array $p): never => $cycleController->makeCurrent($p));
$router->get('/admin/cycles/{id}/setup', fn(array $p): string => $cycleController->setup($p));
$router->post('/admin/cycles/{id}/checklist/{item}', fn(array $p): never => $cycleController->checklist($p));
$router->post('/admin/cycles/{id}/dates', fn(array $p): never => $cycleController->saveDates($p));

/* Annual planning and renewals */
$router->get('/admin/planning', fn(array $p = []): string => $planningController->planning());
$router->get('/admin/planning/{id}', fn(array $p): string => $planningController->editPlan($p));
$router->post('/admin/planning/{id}', fn(array $p): never => $planningController->savePlan($p));
$router->get('/admin/renewals', fn(array $p = []): string => $planningController->renewals());
$router->post('/admin/renewals/{id}', fn(array $p): never => $planningController->renewalAction($p));

/* Allocations, reviewers and rubrics */
$router->get('/admin/allocations', fn(array $p = []): string => $reviewSetupController->allocations());
$router->post('/admin/allocations', fn(array $p = []): never => $reviewSetupController->createAllocation());
$router->get('/admin/allocations/{id}', fn(array $p): string => $reviewSetupController->editAllocation($p));
$router->post('/admin/allocations/{id}', fn(array $p): never => $reviewSetupController->saveAllocation($p));
$router->get('/admin/reviewers', fn(array $p = []): string => $reviewSetupController->reviewers());
$router->post('/admin/reviewers', fn(array $p = []): never => $reviewSetupController->saveReviewer());
$router->get('/admin/rubrics', fn(array $p = []): string => $reviewSetupController->rubrics());
$router->get('/admin/rubrics/{id}', fn(array $p): string => $reviewSetupController->rubric($p));
$router->post('/admin/rubrics/{id}/items', fn(array $p): never => $reviewSetupController->addRubricItem($p));
$router->post('/admin/rubrics/{id}/status', fn(array $p): never => $reviewSetupController->setRubricStatus($p));

/* Thank-you reviewer and reminder workflows */
$router->get('/thank-yous/review', fn(array $p = []): string => $thankYouReviewController->index());
$router->get('/thank-yous/review/zip', fn(array $p = []): never => $thankYouReviewController->zip());
$router->get('/thank-yous/review/{id}/download', fn(array $p): never => $thankYouReviewController->download($p));
$router->get('/admin/thank-you-reminders', fn(array $p = []): string => $thankYouAdminController->reminders());
$router->post('/admin/thank-you-reminders', fn(array $p = []): never => $thankYouAdminController->queueReminders());
$router->get('/admin/thank-you-reviewers', fn(array $p = []): string => $thankYouAdminController->reviewerAccess());
$router->post('/admin/thank-you-reviewers', fn(array $p = []): never => $thankYouAdminController->grantReviewer());
$router->post('/admin/thank-you-reviewers/{id}/remove', fn(array $p): never => $thankYouAdminController->removeReviewer($p));

/* Recipient post-award administration */
$router->get('/admin/distribution-requests', fn(array $p = []): string => $recipientAdminController->distributionRequests());
$router->post('/admin/distribution-requests/{id}', fn(array $p): never => $recipientAdminController->reviewDistributionRequest($p));
$router->get('/admin/thank-yous', fn(array $p = []): string => $recipientAdminController->thankYous());
$router->get('/admin/thank-yous/zip', fn(array $p = []): never => $recipientAdminController->zip());
$router->get('/admin/thank-yous/{id}/download', fn(array $p): never => $recipientAdminController->downloadThankYou($p));

/* Templates and notifications */
$router->get('/admin/templates', fn(array $p = []): string => $templateController->index());
$router->post('/admin/templates/letters', fn(array $p = []): never => $templateController->saveLetter());
$router->post('/admin/templates/email', fn(array $p = []): never => $templateController->saveEmail());
$router->get('/notifications/ready', fn(array $p = []): string => $notificationController->ready());
$router->post('/notifications/queue', fn(array $p = []): never => $notificationController->queue());
$router->get('/notifications/history', fn(array $p = []): string => $notificationController->history());
$router->get('/admin/test-mail', fn(array $p = []): string => $testMailController->index());

/* Dean's Office reallocation */
$router->get('/dean/reallocation', fn(array $p = []): string => $reallocationController->index());
$router->get('/dean/reallocation/{id}', fn(array $p): string => $reallocationController->scholarship($p));
$router->post('/dean/reallocation/{id}/award', fn(array $p): never => $reallocationController->award($p));

/* Dean's Office review */
$router->get('/dean/recommendations', fn(array $p = []): string => $deanReviewController->recommendations());
$router->post('/dean/recommendations/{id}/award', fn(array $p): never => $deanReviewController->createAward($p));
$router->get('/dean/verification', fn(array $p = []): string => $deanReviewController->verification());
$router->post('/dean/verification/{id}/approve', fn(array $p): never => $deanReviewController->approve($p));
$router->post('/dean/verification/{id}/student-teaching-term', fn(array $p): never => $deanReviewController->setStudentTeachingTerm($p));

/* Enrollment snapshots */
$router->get('/admin/enrollment', fn(array $p = []): string => $enrollmentController->index());
$router->post('/admin/enrollment', fn(array $p = []): never => $enrollmentController->import());

/* Staff users */
$router->get('/admin/users', fn(array $p = []): string => $userController->index());
$router->post('/admin/users', fn(array $p = []): never => $userController->create());
$router->post('/admin/users/{id}/toggle', fn(array $p): never => $userController->toggle($p));

/* Program and department scholarship review */
$router->get('/review', fn(array $p = []): string => $reviewController->index());
$router->get('/review/{org}', fn(array $p): string => $reviewController->unit($p));
$router->get('/review/{org}/allocation/{allocation}', fn(array $p): string => $reviewController->allocation($p));
$router->post('/review/{org}/allocation/{allocation}/recommend', fn(array $p): never => $reviewController->recommend($p));
$router->post('/review/{org}/allocation/{allocation}/recommend/remove', fn(array $p): never => $reviewController->removeRecommendation($p));
$router->get('/review/{org}/applicants/{application}', fn(array $p): string => $reviewController->applicant($p));
$router->get('/review/{org}/applicants/{application}/rubric', fn(array $p): string => $reviewController->rubric($p));
$router->post('/review/{org}/applicants/{application}/rubric', fn(array $p): never => $reviewController->saveRubric($p));
$router->post('/review/{org}/submit', fn(array $p): never => $reviewController->submit($p));

/* Applicant import and official program mapping */
$router->get('/admin/applicants', fn(array $p = []): string => $applicantController->index());
$router->post('/admin/applicants/imports', fn(array $p = []): never => $applicantController->stageImport());
$router->get('/admin/applicants/imports/{id}/map', fn(array $p): string => $applicantController->mapping($p));
$router->post('/admin/applicants/imports/{id}/process', fn(array $p): never => $applicantController->process($p));
$router->get('/admin/applicants/mappings', fn(array $p = []): string => $applicantController->mappings());
$router->post('/admin/applicants/mappings/{id}', fn(array $p): never => $applicantController->resolveMapping($p));
$router->get('/admin/applicants/{id}', fn(array $p): string => $applicantController->applicant($p));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    $response = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
    if (is_string($response)) {
        echo $response;
    }
} catch (\Throwable $e) {
    http_response_code(500);

    $message = (getenv('APP_ENV') ?: 'development') === 'production'
        ? 'An unexpected error occurred.'
        : $e->getMessage();

    echo View::layout(
        'Application error',
        '<div class="error" role="alert"><h1>Application error</h1><p>' . View::e($message) . '</p></div>',
        $auth->check() ? $auth->displayName() : null,
        $auth->check() ? $auth->staffRole() : null
    );
}
