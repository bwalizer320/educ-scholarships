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
use App\Controllers\AwardWorkspaceController;
use App\Controllers\RecipientController;
use App\Controllers\ActivationController;
use App\Controllers\Admin\ApplicantController;
use App\Controllers\Admin\AnnualAwardAuthorityController;
use App\Controllers\Admin\CycleController;
use App\Controllers\Admin\CycleWizardController;
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
$cycleWizard = new CycleWizardController($pdo, $auth);
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
$annualAwardAuthorityController = new AnnualAwardAuthorityController($pdo, $auth);
$userController = new UserController($pdo, $auth);
$reviewController = new ReviewController($pdo, $auth, new ProgramScopePolicy($pdo));
$awardWorkspace = new AwardWorkspaceController($pdo, $auth);

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

/* The three primary screens: inventory, recipient recommendations, and processing. */
$router->get('/dashboard', fn(array $p = []): never => $awardWorkspace->home());
$router->get('/awards/available', fn(array $p = []): string => $awardWorkspace->available());
$router->get('/awards/recommendations', fn(array $p = []): string => $awardWorkspace->recommendations());
$router->post('/awards/recommendations/recruitment', fn(array $p = []): never => $awardWorkspace->nominateRecruitment());
$router->get('/awards/tracker', fn(array $p = []): string => $awardWorkspace->tracker());
$router->get('/awards/allocations/template', fn(array $p = []): never => $awardWorkspace->allocationTemplate());
$router->post('/awards/allocations/import', fn(array $p = []): never => $awardWorkspace->importAllocations());
$router->post('/awards/maui-export', fn(array $p = []): never => $awardWorkspace->exportMaui());

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
$router->get('/setup', fn(array $p = []): never => $cycleWizard->current());
$router->get('/admin/cycles/{id}/setup', fn(array $p): string => $cycleWizard->index($p));
$router->post('/admin/cycles/{id}/wizard/awards', fn(array $p): never => $cycleWizard->uploadAwards($p));
$router->post('/admin/cycles/{id}/wizard/assignment', fn(array $p): never => $cycleWizard->saveAssignment($p));
$router->post('/admin/cycles/{id}/wizard/reset', fn(array $p): never => $cycleWizard->resetDemo($p));
$router->post('/admin/cycles/{id}/wizard/open', fn(array $p): never => $cycleWizard->openReview($p));
$router->post('/admin/cycles/{id}/checklist/{item}', fn(array $p): never => $cycleController->checklist($p));
$router->post('/admin/cycles/{id}/dates', fn(array $p): never => $cycleController->saveDates($p));

/* Annual award authority */
$router->get('/admin/award-authority', fn(array $p = []): string => $annualAwardAuthorityController->index());
$router->post('/admin/award-authority', fn(array $p = []): never => $annualAwardAuthorityController->import());

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
