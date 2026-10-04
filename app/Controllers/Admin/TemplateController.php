<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class TemplateController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->currentCycle();

        $letterRows = '';
        foreach ($this->pdo->query(
            'SELECT * FROM letter_templates ORDER BY template_type, version_number DESC'
        )->fetchAll() as $row) {
            $letterRows .= '<tr><td>' . View::e($this->letterLabel($row['template_type'])) . '</td>'
                . '<td>' . View::e($row['name']) . '</td>'
                . '<td>' . (int) $row['version_number'] . '</td>'
                . '<td>' . ((int) $row['active'] === 1 ? View::status('confirmed') : View::status('closed')) . '</td></tr>';
        }
        if ($letterRows === '') {
            $letterRows = '<tr><td colspan="4">No award letter templates have been configured.</td></tr>';
        }

        $emailRows = '';
        foreach ($this->pdo->query(
            'SELECT * FROM email_templates ORDER BY template_type, version_number DESC'
        )->fetchAll() as $row) {
            $emailRows .= '<tr><td>' . View::e(ucwords(str_replace('_', ' ', $row['template_type']))) . '</td>'
                . '<td>' . View::e($row['subject_template']) . '</td>'
                . '<td>' . (int) $row['version_number'] . '</td>'
                . '<td>' . ((int) $row['active'] === 1 ? View::status('confirmed') : View::status('closed')) . '</td></tr>';
        }
        if ($emailRows === '') {
            $emailRows = '<tr><td colspan="4">No email templates have been configured.</td></tr>';
        }

        $mergeHelp = '<code>{{student_first_name}}</code>, <code>{{student_full_name}}</code>, '
            . '<code>{{scholarship_name}}</code>, <code>{{academic_year}}</code>, '
            . '<code>{{award_total}}</code>, <code>{{fall_amount}}</code>, <code>{{spring_amount}}</code>, '
            . '<code>{{thank_you_deadline}}</code>, <code>{{student_teaching_language}}</code>, '
            . '<code>{{student_portal_url}}</code>, <code>{{portal_access_url}}</code>, and <code>{{activation_url}}</code>.';

        $body = '<div class="page-header"><div><h1>Letter and email templates</h1>'
            . '<p>Create versioned templates used to generate immutable award letters and notification emails.</p></div></div>'
            . '<div class="notice"><strong>Merge fields:</strong> ' . $mergeHelp . '</div>'
            . '<section class="card"><h2>Add award letter template</h2>'
            . '<form method="post" action="/admin/templates/letters">' . View::csrfField()
            . '<div class="form-grid"><div><label for="letter_type">Template type</label><select id="letter_type" name="template_type">'
            . '<option value="new_award">New award</option><option value="renewal">Renewal</option></select></div>'
            . '<div><label for="letter_name">Template name</label><input id="letter_name" name="name" required></div></div>'
            . '<label for="letter_html">Letter body HTML</label><textarea id="letter_html" name="html_template" required></textarea>'
            . '<p class="muted">Use simple semantic HTML such as paragraphs, headings, strong/emphasis, and lists. University of Iowa letterhead styling is added during PDF generation.</p>'
            . '<div class="form-actions"><button type="submit">Save new letter version</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Letter versions</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Type</th><th>Name</th><th>Version</th><th>Status</th></tr></thead><tbody>' . $letterRows . '</tbody></table></div></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Add email template</h2>'
            . '<form method="post" action="/admin/templates/email">' . View::csrfField()
            . '<div class="form-grid"><div><label for="email_type">Template type</label><select id="email_type" name="template_type">'
            . '<option value="award_new">New award</option><option value="award_renewal">Renewal award</option><option value="thank_you_reminder">Thank-you reminder</option></select></div>'
            . '<div><label for="email_subject">Subject</label><input id="email_subject" name="subject_template" required></div></div>'
            . '<label for="email_html">Email body HTML</label><textarea id="email_html" name="html_template" required></textarea>'
            . '<div class="form-actions"><button type="submit">Save new email version</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Email versions</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Type</th><th>Subject</th><th>Version</th><th>Status</th></tr></thead><tbody>' . $emailRows . '</tbody></table></div></section>';

        return $this->render('Templates', $body, $cycle);
    }

    public function saveLetter(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->currentCycle();

        try {
            $type = (string) ($_POST['template_type'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            $html = trim((string) ($_POST['html_template'] ?? ''));

            if (!in_array($type, ['new_award','renewal'], true) || $name === '' || $html === '') {
                throw new RuntimeException('Complete the award letter template fields.');
            }

            $versionStmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(version_number),0) + 1 FROM letter_templates WHERE template_type = ?'
            );
            $versionStmt->execute([$type]);
            $version = (int) $versionStmt->fetchColumn();

            $this->pdo->beginTransaction();
            $this->pdo->prepare('UPDATE letter_templates SET active = 0 WHERE template_type = ?')->execute([$type]);

            $insert = $this->pdo->prepare(
                'INSERT INTO letter_templates (
                    template_type,name,html_template,active,version_number
                 ) VALUES (?,?,?,1,?)'
            );
            $insert->execute([$type,$name,$html,$version]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            $this->audit('template.letter_created','letter_template',$id,$cycle ? (int)$cycle['id'] : null,null,[
                'template_type'=>$type,'version'=>$version
            ]);
            Flash::success('Award letter template saved as the active version.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/templates');
    }

    public function saveEmail(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->currentCycle();

        try {
            $type = (string) ($_POST['template_type'] ?? '');
            $subject = trim((string) ($_POST['subject_template'] ?? ''));
            $html = trim((string) ($_POST['html_template'] ?? ''));

            if (!in_array($type, ['award_new','award_renewal','thank_you_reminder'], true) || $subject === '' || $html === '') {
                throw new RuntimeException('Complete the email template fields.');
            }

            $versionStmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(version_number),0) + 1 FROM email_templates WHERE template_type = ?'
            );
            $versionStmt->execute([$type]);
            $version = (int) $versionStmt->fetchColumn();

            $this->pdo->beginTransaction();
            $this->pdo->prepare('UPDATE email_templates SET active = 0 WHERE template_type = ?')->execute([$type]);

            $insert = $this->pdo->prepare(
                'INSERT INTO email_templates (
                    template_type,subject_template,html_template,active,version_number
                 ) VALUES (?,?,?,1,?)'
            );
            $insert->execute([$type,$subject,$html,$version]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            $this->audit('template.email_created','email_template',$id,$cycle ? (int)$cycle['id'] : null,null,[
                'template_type'=>$type,'version'=>$version
            ]);
            Flash::success('Email template saved as the active version.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/templates');
    }

    private function letterLabel(string $type): string
    {
        return $type === 'renewal' ? 'Renewal award' : 'New award';
    }
}
