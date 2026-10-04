<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Controllers\Admin\ApplicantController;
use App\Controllers\Admin\CycleController;
use App\Controllers\Admin\EnrollmentController;
use App\Controllers\Admin\PlanningController;
use App\Controllers\Admin\ReviewSetupController;
use App\Database\Connection;
use App\Http\Csrf;
use App\Http\Router;
use App\Support\Flash;
use App\Support\View;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$router = new Router();

$cycleController = new CycleController($pdo, $auth);
$enrollmentController = new EnrollmentController($pdo, $auth);
$planningController = new PlanningController($pdo, $auth);
$reviewSetupController = new ReviewSetupController($pdo, $auth);
$applicantController = new ApplicantController($pdo, $auth);
$userController = new UserController($pdo, $auth);
$reviewController = new ReviewController($pdo, $auth, new ProgramScopePolicy($pdo));

$router->get('/', static function () use ($auth): never {
    header('Location: ' . ($auth->check() ? '/dashboard' : '/login'));
    exit;
});

$router->get('/login', static function () use ($auth): string {
    if ($auth->check()) {
        header('Location: /dashboard');
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

        header('Location: /dashboard');
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

    $setupAction = $cycle
        ? '<a class="button" href="/admin/cycles/' . $cycleId . '/setup">Continue cycle setup</a>'
        : '<a class="button" href="/admin/cycles">Create academic cycle</a>';

    $body = Flash::render()
        . '<div class="page-header"><div><h1>Scholarship Manager</h1><p><strong>' . $cycleLabel . '</strong> · ' . $cycleStatus . '</p></div>'
        . '<form method="post" action="/logout">' . $token . '<button class="secondary" type="submit">Sign out</button></form></div>'
        . '<div class="grid" aria-label="Current cycle summary">'
        . '<section class="stat"><span>Scholarships</span><strong>' . $scholarships . '</strong></section>'
        . '<section class="stat"><span>Renewals needing review</span><strong>' . $renewals . '</strong></section>'
        . '<section class="stat"><span>Applicants</span><strong>' . $applicants . '</strong></section>'
        . '<section class="stat"><span>Review units outstanding</span><strong>' . $reviewUnits . '</strong></section>'
        . '<section class="stat"><span>Program mappings unresolved</span><strong>' . $unresolvedMappings . '</strong></section>'
        . '</div>'
        . '<section class="card" style="margin-top:1rem"><h2>Current workflow</h2><div class="actions">'
        . $setupAction
        . '<a class="button secondary" href="/admin/planning">Annual planning</a>'
        . '<a class="button secondary" href="/admin/renewals">Renewals</a>'
        . '<a class="button secondary" href="/admin/allocations">Allocations</a>'
        . '<a class="button secondary" href="/admin/applicants">Applicants</a>'
        . '<a class="button secondary" href="/review">Program review</a>'
        . '</div></section>';

    return View::layout(
        'Dashboard',
        $body,
        $auth->displayName(),
        $auth->staffRole(),
        $cycle['label'] ?? null
    );
});

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
