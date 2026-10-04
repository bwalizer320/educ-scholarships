<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\View;

final class OrganizationController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->currentCycle();

        $departments = $this->pdo->query(
            "SELECT * FROM org_units
             WHERE unit_type = 'department' AND active = 1
             ORDER BY name"
        )->fetchAll();

        $sections = '';
        foreach ($departments as $department) {
            $programStmt = $this->pdo->prepare(
                "SELECT p.*,
                        (SELECT COUNT(*) FROM program_offerings po WHERE po.org_unit_id = p.id AND po.active = 1) AS offering_count
                 FROM org_units p
                 WHERE p.parent_id = ? AND p.unit_type = 'program' AND p.active = 1
                 ORDER BY p.name"
            );
            $programStmt->execute([(int)$department['id']]);

            $rows = '';
            foreach ($programStmt->fetchAll() as $program) {
                $offeringStmt = $this->pdo->prepare(
                    "SELECT pgms_program_descr, pgms_objective_key,
                            pgms_sub_program_descr, pgms_sub_program_id
                     FROM program_offerings
                     WHERE org_unit_id = ? AND active = 1
                     ORDER BY pgms_objective_key, pgms_sub_program_descr"
                );
                $offeringStmt->execute([(int)$program['id']]);

                $offerings = '';
                foreach ($offeringStmt->fetchAll() as $offering) {
                    $label = trim(implode(' · ', array_filter([
                        $offering['pgms_objective_key'] ?? null,
                        $offering['pgms_sub_program_descr'] ?? null,
                        $offering['pgms_sub_program_id'] ?? null,
                    ], static fn($v) => $v !== null && $v !== '')));

                    $offerings .= '<li>' . View::e($label !== '' ? $label : $offering['pgms_program_descr']) . '</li>';
                }

                if ($offerings === '') {
                    $offerings = '<li class="muted">No active official offering rows</li>';
                }

                $rows .= '<tr>'
                    . '<td><strong>' . View::e($program['name']) . '</strong><br><span class="muted">' . View::e($program['code'] ?? '') . '</span></td>'
                    . '<td>' . (int)$program['offering_count'] . '</td>'
                    . '<td><ul class="criteria">' . $offerings . '</ul></td>'
                    . '</tr>';
            }

            if ($rows === '') {
                $rows = '<tr><td colspan="3">No active programs are assigned to this department.</td></tr>';
            }

            $sections .= '<section class="card" style="margin-bottom:1rem">'
                . '<h2>' . View::e($department['name']) . '</h2>'
                . '<div class="table-wrap"><table><thead><tr><th>Canonical program</th><th>Official offerings</th><th>MAUI / program-system relationships</th></tr></thead><tbody>'
                . $rows . '</tbody></table></div></section>';
        }

        if ($sections === '') {
            $sections = '<div class="notice">No department hierarchy has been seeded yet. Run <code>php bin/console seed:base</code>.</div>';
        }

        $unresolved = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM program_mapping_queue WHERE status = 'unresolved'"
        )->fetchColumn();

        $body = '<div class="page-header"><div><h1>College program hierarchy</h1>'
            . '<p>This is the canonical Department → Program architecture used by scholarship allocations, reviewer scope, applicant mapping, and enrollment imports. Import source labels map into this hierarchy; they do not create new programs.</p></div>'
            . '<a class="button secondary" href="/admin/applicants/mappings">Unresolved mappings (' . $unresolved . ')</a></div>'
            . $sections;

        return $this->render('Program hierarchy',$body,$cycle);
    }
}
