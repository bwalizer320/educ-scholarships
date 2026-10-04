<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Imports\ApplicantImportService;
use App\Services\Imports\ProgramMappingService;
use App\Services\Imports\TabularFileReader;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class ApplicantController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $importsStmt = $this->pdo->prepare(
            'SELECT ai.*, u.display_name AS imported_by
             FROM application_imports ai
             JOIN users u ON u.id = ai.imported_by_user_id
             WHERE ai.cycle_id = ?
             ORDER BY ai.created_at DESC'
        );
        $importsStmt->execute([(int) $cycle['id']]);

        $importRows = '';
        foreach ($importsStmt->fetchAll() as $row) {
            $action = '<a class="button secondary small" href="/admin/applicants/imports/' . (int) $row['id'] . '/map">Mapping</a>';
            $importRows .= '<tr>'
                . '<td>' . View::e($row['filename']) . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td>' . (int) $row['row_count'] . '</td>'
                . '<td>' . (int) $row['inserted_count'] . '</td>'
                . '<td>' . (int) $row['updated_count'] . '</td>'
                . '<td>' . (int) $row['error_count'] . '</td>'
                . '<td>' . View::e($row['imported_by']) . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        if ($importRows === '') {
            $importRows = '<tr><td colspan="8">No applicant files have been imported for this cycle.</td></tr>';
        }

        $appStmt = $this->pdo->prepare(
            "SELECT a.id, s.display_name, s.university_id, s.email, ou.name AS program_name,
                    a.classification, a.gpa, a.residency_state, a.financial_need
             FROM applications a
             JOIN students s ON s.id = a.student_id
             LEFT JOIN org_units ou ON ou.id = a.org_unit_id
             WHERE a.cycle_id = ? AND a.active = 1
             ORDER BY s.last_name, s.first_name"
        );
        $appStmt->execute([(int) $cycle['id']]);

        $appRows = '';
        foreach ($appStmt->fetchAll() as $row) {
            $need = $row['financial_need'] === null ? '—' : ((int) $row['financial_need'] === 1 ? 'Yes' : 'No');
            $appRows .= '<tr>'
                . '<td><a href="/admin/applicants/' . (int) $row['id'] . '">' . View::e($row['display_name']) . '</a><br><span class="muted">' . View::e($row['university_id']) . '</span></td>'
                . '<td>' . View::e($row['program_name'] ?? 'Needs mapping') . '</td>'
                . '<td>' . View::e($row['classification'] ?? '—') . '</td>'
                . '<td>' . View::e($row['gpa'] ?? '—') . '</td>'
                . '<td>' . View::e($row['residency_state'] ?? '—') . '</td>'
                . '<td>' . View::e($need) . '</td>'
                . '</tr>';
        }

        if ($appRows === '') {
            $appRows = '<tr><td colspan="6">No applicants loaded for this cycle.</td></tr>';
        }

        $unresolved = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM program_mapping_queue WHERE status = 'unresolved'"
        )->fetchColumn();

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Applicants</h1>
        <p>Import the University scholarship applicant file, map its columns, and normalize programs to the official College hierarchy.</p>
    </div>
    <a class="button secondary" href="/admin/applicants/mappings">Program mappings ({$unresolved})</a>
</div>

<section class="card">
    <h2>Upload applicant export</h2>
    <form method="post" action="/admin/applicants/imports" enctype="multipart/form-data">
        {$this->csrf()}
        <label for="applicant_file">Applicant file</label>
        <input id="applicant_file" name="applicant_file" type="file" accept=".csv,.tsv,.txt,.xls,.xlsx" required>
        <p class="muted">Accepted formats: CSV, TSV, XLS, and XLSX. The next screen lets you assign source columns to standard scholarship fields.</p>
        <div class="form-actions"><button type="submit">Upload and map columns</button></div>
    </form>
</section>

<section class="card" style="margin-top:1rem">
    <h2>Import history</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>File</th><th>Status</th><th>Rows</th><th>New</th><th>Updated</th><th>Errors</th><th>Imported by</th><th>Action</th></tr></thead>
        <tbody>{$importRows}</tbody>
    </table></div>
</section>

<section class="card" style="margin-top:1rem">
    <h2>Current applicant pool</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Student</th><th>Program</th><th>Classification</th><th>GPA</th><th>Residency</th><th>Financial need</th></tr></thead>
        <tbody>{$appRows}</tbody>
    </table></div>
</section>
HTML;

        return $this->render('Applicants', $body, $cycle);
    }

    public function stageImport(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $file = $_FILES['applicant_file'] ?? null;

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a valid applicant file to upload.');
            }

            $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv','tsv','txt','xls','xlsx'], true)) {
                throw new RuntimeException('Applicant file must be CSV, TSV, XLS, or XLSX.');
            }

            if ((int) ($file['size'] ?? 0) > 25 * 1024 * 1024) {
                throw new RuntimeException('Applicant import file is larger than 25 MB.');
            }

            $service = $this->importService();
            $result = $service->stage(
                (int) $cycle['id'],
                (int) $this->auth->userId(),
                (string) $file['tmp_name'],
                (string) $file['name']
            );

            $this->audit(
                'applicant_import.staged',
                'application_import',
                (int) $result['import_id'],
                (int) $cycle['id'],
                null,
                ['filename' => $file['name']]
            );
            Flash::success('File uploaded. Map the source columns before importing.');
            $this->redirect('/admin/applicants/imports/' . (int) $result['import_id'] . '/map');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
            $this->redirect('/admin/applicants');
        }
    }

    public function mapping(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        try {
            $data = $this->importService()->preview($id);
        } catch (\Throwable $e) {
            return $this->render('Import mapping', '<div class="error">' . View::e($e->getMessage()) . '</div>', $cycle);
        }

        if ((int) $data['import']['cycle_id'] !== (int) $cycle['id']) {
            http_response_code(404);
            return $this->render('Import mapping', '<div class="error">Applicant import is not part of the current cycle.</div>', $cycle);
        }

        $existing = [];
        if (!empty($data['import']['column_mapping_json'])) {
            $existing = json_decode((string) $data['import']['column_mapping_json'], true) ?: [];
        }

        $fields = [
            'university_id' => ['University ID', true, ['university_id','student id','univ id','id']],
            'display_name' => ['Full name', false, ['standard_full_name','student name','full name','name']],
            'first_name' => ['First name', false, ['first name','first_name']],
            'last_name' => ['Last name', false, ['last name','last_name']],
            'email' => ['Email', true, ['email','email address']],
            'program' => ['Program', true, ['pgms_program_descr','program','major']],
            'objective' => ['Degree / objective', false, ['pgms_objective_key','degree objective','objective','degree']],
            'subprogram' => ['Subprogram / track', false, ['pgms_sub_program_descr','subprogram','track']],
            'subprogram_id' => ['Subprogram ID', false, ['pgms_sub_program_id','subprogram id']],
            'academic_level' => ['Academic level', false, ['academic level','level']],
            'classification' => ['Class standing', false, ['stud_classification_descr','classification','class standing','class year']],
            'gpa' => ['GPA', false, ['cum_ui_graded_gpa','gpa','cumulative gpa']],
            'residency_state' => ['Residency state', false, ['residency_state_descr','residency state','state']],
            'residency_county' => ['Residency county', false, ['residency_county_descr','residency county','county']],
            'citizenship_country' => ['Citizenship country', false, ['citizenship_country_descr','citizenship','citizenship country']],
            'first_generation' => ['First generation', false, ['first generation','first_generation','is_parent_higher_ed_grad']],
            'financial_need' => ['Financial need', false, ['financial need','financial_need','need']],
        ];

        $mappingHtml = '';
        foreach ($fields as $key => [$label, $required, $candidates]) {
            $selected = $existing[$key] ?? $this->guessHeader($data['headers'], $candidates);
            $mappingHtml .= '<div><label for="map_' . View::e($key) . '">' . View::e($label)
                . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
                . '<select id="map_' . View::e($key) . '" name="mapping[' . View::e($key) . ']"'
                . ($required ? ' required' : '') . '>'
                . $this->headerOptions($data['headers'], $selected, !$required)
                . '</select></div>';
        }

        $previewHeaders = '';
        foreach ($data['headers'] as $header) {
            $previewHeaders .= '<th>' . View::e($header) . '</th>';
        }

        $previewRows = '';
        foreach ($data['preview'] as $row) {
            $previewRows .= '<tr>';
            foreach ($data['headers'] as $header) {
                $value = (string) ($row[$header] ?? '');
                $previewRows .= '<td>' . View::e(mb_strimwidth($value, 0, 80, '…')) . '</td>';
            }
            $previewRows .= '</tr>';
        }

        $summary = $data['import']['error_summary']
            ? '<div class="notice">' . View::e($data['import']['error_summary']) . '</div>'
            : '';

        $body = '<div class="page-header"><div><h1>Map applicant columns</h1><p>'
            . View::e($data['import']['filename']) . '</p></div><a class="button secondary" href="/admin/applicants">Back to applicants</a></div>'
            . $summary
            . '<section class="card"><h2>Column mapping</h2><p>Required fields are University ID, email, and program. Unused source fields are retained in the application record for later review.</p>'
            . '<form method="post" action="/admin/applicants/imports/' . $id . '/process">' . View::csrfField()
            . '<div class="form-grid">' . $mappingHtml . '</div>'
            . '<div class="form-actions"><button type="submit">Import applicants</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Source preview</h2><div class="table-wrap"><table><thead><tr>'
            . $previewHeaders . '</tr></thead><tbody>' . $previewRows . '</tbody></table></div></section>';

        return $this->render('Applicant column mapping', $body, $cycle);
    }

    public function process(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        try {
            $mapping = $_POST['mapping'] ?? [];
            if (!is_array($mapping)) {
                throw new RuntimeException('Applicant column mapping is invalid.');
            }

            $result = $this->importService()->process($id, $mapping);

            $this->audit(
                'applicant_import.processed',
                'application_import',
                $id,
                (int) $cycle['id'],
                null,
                $result
            );

            if ($result['needsMapping'] > 0) {
                Flash::error(
                    $result['needsMapping'] . ' applicant row(s) need an official program mapping. Resolve them, then run the import again.'
                );
                $this->redirect('/admin/applicants/mappings');
            }

            Flash::success(
                'Applicant import completed: ' . $result['inserted'] . ' new, '
                . $result['updated'] . ' updated, ' . $result['errors'] . ' row errors.'
            );
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/applicants');
    }

    public function mappings(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $queue = $this->pdo->query(
            "SELECT * FROM program_mapping_queue
             WHERE status = 'unresolved'
             ORDER BY occurrence_count DESC, source_program, source_subprogram"
        )->fetchAll();

        $offerings = $this->pdo->query(
            "SELECT po.id, po.org_unit_id, po.pgms_program_descr, po.pgms_objective_key,
                    po.pgms_sub_program_descr, po.pgms_sub_program_id, ou.name AS canonical_name
             FROM program_offerings po
             JOIN org_units ou ON ou.id = po.org_unit_id
             WHERE po.active = 1
             ORDER BY ou.name, po.pgms_objective_key"
        )->fetchAll();

        $rows = '';
        foreach ($queue as $item) {
            $options = '<option value="">Select official program</option>';
            foreach ($offerings as $offering) {
                $options .= '<option value="' . (int) $offering['id'] . '">'
                    . View::e($offering['canonical_name'])
                    . ' — ' . View::e($offering['pgms_objective_key'])
                    . ' — ' . View::e($offering['pgms_sub_program_descr'] ?? '')
                    . '</option>';
            }

            $source = View::e($item['source_program']);
            if ($item['source_objective'] !== '') {
                $source .= '<br><span class="muted">' . View::e($item['source_objective']) . '</span>';
            }
            if ($item['source_subprogram'] !== '') {
                $source .= '<br><span class="muted">' . View::e($item['source_subprogram']) . '</span>';
            }

            $rows .= '<tr><td>' . $source . '</td><td>' . View::e($item['source_system']) . '</td><td>' . (int) $item['occurrence_count'] . '</td>'
                . '<td><form method="post" action="/admin/applicants/mappings/' . (int) $item['id'] . '">' . View::csrfField()
                . '<label class="muted" for="offering_' . (int) $item['id'] . '">Official program</label>'
                . '<select id="offering_' . (int) $item['id'] . '" name="program_offering_id" required>' . $options . '</select>'
                . '<button class="small" type="submit" style="margin-top:.4rem">Resolve mapping</button></form></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4">No unresolved program mappings.</td></tr>';
        }

        $body = <<<HTML
<div class="page-header">
<div><h1>Program mapping queue</h1><p>Unknown source labels must be mapped to the official College program architecture. The importer never creates a new program from an arbitrary source value.</p></div>
<a class="button secondary" href="/admin/applicants">Applicants</a>
</div>
<div class="table-wrap"><table>
<thead><tr><th>Source value</th><th>Source</th><th>Occurrences</th><th>Resolve to</th></tr></thead>
<tbody>{$rows}</tbody>
</table></div>
HTML;

        return $this->render('Program mappings', $body, $cycle);
    }

    public function resolveMapping(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $queueId = (int) ($params['id'] ?? 0);
        $offeringId = (int) ($_POST['program_offering_id'] ?? 0);

        try {
            $queueStmt = $this->pdo->prepare('SELECT * FROM program_mapping_queue WHERE id = ? AND status = "unresolved"');
            $queueStmt->execute([$queueId]);
            $queue = $queueStmt->fetch();

            $offeringStmt = $this->pdo->prepare('SELECT id, org_unit_id FROM program_offerings WHERE id = ? AND active = 1');
            $offeringStmt->execute([$offeringId]);
            $offering = $offeringStmt->fetch();

            if (!$queue || !$offering) {
                throw new RuntimeException('Program mapping or official program could not be found.');
            }

            $this->pdo->beginTransaction();

            $alias = $this->pdo->prepare(
                'INSERT INTO program_mapping_aliases (
                    source_system, source_program, source_objective, source_subprogram,
                    source_subprogram_id, program_offering_id, org_unit_id, active
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $alias->execute([
                $queue['source_system'],
                $queue['source_program'],
                $queue['source_objective'],
                $queue['source_subprogram'],
                $queue['source_subprogram_id'],
                $offeringId,
                $offering['org_unit_id'],
            ]);

            $resolve = $this->pdo->prepare(
                "UPDATE program_mapping_queue
                 SET status = 'resolved', resolved_org_unit_id = ?,
                     resolved_program_offering_id = ?, resolved_by_user_id = ?,
                     resolved_at = NOW()
                 WHERE id = ?"
            );
            $resolve->execute([
                $offering['org_unit_id'],
                $offeringId,
                $this->auth->userId(),
                $queueId,
            ]);

            $this->pdo->commit();

            $this->audit('program_mapping.resolved', 'program_mapping_queue', $queueId, (int) $cycle['id'], null, [
                'program_offering_id' => $offeringId,
                'org_unit_id' => (int) $offering['org_unit_id'],
            ]);
            Flash::success('Program mapping resolved. Re-run any affected applicant import.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/applicants/mappings');
    }

    public function applicant(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT a.*, s.display_name, s.university_id, s.email,
                    ou.name AS program_name, po.pgms_objective_key,
                    po.pgms_sub_program_descr
             FROM applications a
             JOIN students s ON s.id = a.student_id
             LEFT JOIN org_units ou ON ou.id = a.org_unit_id
             LEFT JOIN program_offerings po ON po.id = a.program_offering_id
             WHERE a.id = ? AND a.cycle_id = ?"
        );
        $stmt->execute([$id, (int) $cycle['id']]);
        $app = $stmt->fetch();

        if (!$app) {
            http_response_code(404);
            return $this->render('Applicant not found', '<div class="error">Applicant not found.</div>', $cycle);
        }

        $eligStmt = $this->pdo->prepare(
            "SELECT ea.id, ea.status, ea.evaluated_at, s.name AS scholarship_name
             FROM eligibility_assessments ea
             JOIN cycle_scholarships cs ON cs.id = ea.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE ea.student_id = ? AND cs.cycle_id = ?
             ORDER BY s.name, ea.evaluated_at DESC"
        );
        $eligStmt->execute([(int) $app['student_id'], (int) $cycle['id']]);

        $eligRows = '';
        foreach ($eligStmt->fetchAll() as $elig) {
            $eligRows .= '<tr><td>' . View::e($elig['scholarship_name']) . '</td><td>' . View::status($elig['status']) . '</td><td>' . View::e($elig['evaluated_at']) . '</td></tr>';
        }
        if ($eligRows === '') {
            $eligRows = '<tr><td colspan="3">Eligibility has not been calculated for this student yet.</td></tr>';
        }

        $need = $app['financial_need'] === null ? 'Unknown' : ((int) $app['financial_need'] === 1 ? 'Yes' : 'No');
        $firstGen = $app['first_generation'] === null ? 'Unknown' : ((int) $app['first_generation'] === 1 ? 'Yes' : 'No');

        $body = '<div class="page-header"><div><h1>' . View::e($app['display_name']) . '</h1><p>' . View::e($app['university_id']) . ' · ' . View::e($app['email']) . '</p></div>'
            . '<a class="button secondary" href="/admin/applicants">Back to applicants</a></div>'
            . '<div class="grid">'
            . '<section class="stat"><span>Program</span><strong style="font-size:1.1rem">' . View::e($app['program_name'] ?? 'Needs mapping') . '</strong></section>'
            . '<section class="stat"><span>Degree</span><strong style="font-size:1.1rem">' . View::e($app['pgms_objective_key'] ?? $app['degree_objective'] ?? '—') . '</strong></section>'
            . '<section class="stat"><span>GPA</span><strong>' . View::e($app['gpa'] ?? '—') . '</strong></section>'
            . '<section class="stat"><span>Financial need</span><strong style="font-size:1.1rem">' . View::e($need) . '</strong></section>'
            . '</div>'
            . '<section class="card" style="margin-top:1rem"><h2>Imported values</h2><div class="table-wrap"><table><tbody>'
            . '<tr><th>Classification</th><td>' . View::e($app['classification'] ?? '—') . '</td></tr>'
            . '<tr><th>Residency</th><td>' . View::e($app['residency_state'] ?? '—') . '</td></tr>'
            . '<tr><th>County</th><td>' . View::e($app['residency_county'] ?? '—') . '</td></tr>'
            . '<tr><th>Citizenship</th><td>' . View::e($app['citizenship_country'] ?? '—') . '</td></tr>'
            . '<tr><th>First generation</th><td>' . View::e($firstGen) . '</td></tr>'
            . '</tbody></table></div></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Scholarship eligibility</h2><div class="table-wrap"><table><thead><tr><th>Scholarship</th><th>Status</th><th>Evaluated</th></tr></thead><tbody>'
            . $eligRows . '</tbody></table></div></section>';

        return $this->render($app['display_name'], $body, $cycle);
    }

    private function importService(): ApplicantImportService
    {
        return new ApplicantImportService(
            $this->pdo,
            new LocalFileStorage(),
            new TabularFileReader(),
            new ProgramMappingService($this->pdo)
        );
    }

    private function guessHeader(array $headers, array $candidates): ?string
    {
        foreach ($headers as $header) {
            $normalized = strtolower(trim($header));
            foreach ($candidates as $candidate) {
                if ($normalized === strtolower($candidate)) {
                    return $header;
                }
            }
        }

        return null;
    }

    private function headerOptions(array $headers, ?string $selected, bool $allowBlank): string
    {
        $html = $allowBlank ? '<option value="">Not mapped</option>' : '<option value="">Select column</option>';

        foreach ($headers as $header) {
            $html .= '<option value="' . View::e($header) . '"'
                . ($selected === $header ? ' selected' : '') . '>' . View::e($header) . '</option>';
        }

        return $html;
    }

    private function csrf(): string
    {
        return View::csrfField();
    }
}
