<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class ScholarshipController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->currentCycle();

        $stmt = $this->pdo->query(
            "SELECT s.*, siv.version_number, sar.renewable, sar.amount_mode,
                    (SELECT COUNT(*) FROM cycle_scholarships cs WHERE cs.scholarship_id = s.id) AS cycle_count
             FROM scholarships s
             LEFT JOIN scholarship_intent_versions siv ON siv.id = s.current_intent_version_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = siv.id
             ORDER BY s.active DESC, s.name"
        );

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr>'
                . '<td><a href="/admin/scholarships/' . (int)$row['id'] . '"><strong>' . View::e($row['name']) . '</strong></a></td>'
                . '<td>' . View::e($row['uica_account_number']) . '</td>'
                . '<td>' . View::e($row['mfk'] ?? '—') . '</td>'
                . '<td>' . ((int)($row['renewable'] ?? 0) === 1 ? 'Yes' : 'No') . '</td>'
                . '<td>' . View::e(ucwords(str_replace('_',' ',$row['amount_mode'] ?? ''))) . '</td>'
                . '<td>' . (int)$row['cycle_count'] . '</td>'
                . '<td>' . ((int)$row['active'] === 1 ? View::status('confirmed') : View::status('closed')) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">No scholarships are in the catalog yet.</td></tr>';
        }

        $body = Flash::render()
            . '<div class="page-header"><div><h1>Scholarship catalog</h1>'
            . '<p>Permanent scholarship identity and donor intent live here. Annual amounts and program allocations remain cycle-specific.</p></div></div>'
            . '<section class="card"><h2>Add scholarship</h2><form method="post" action="/admin/scholarships">'
            . View::csrfField()
            . '<div class="form-grid">'
            . '<div><label for="uica">UICA account number</label><input id="uica" name="uica_account_number" required></div>'
            . '<div><label for="mfk">MFK</label><input id="mfk" name="mfk"></div>'
            . '<div><label for="name">Scholarship name</label><input id="name" name="name" required></div>'
            . '<div><label for="award_type">Type</label><select id="award_type" name="award_type">'
            . '<option value="scholarship">Scholarship</option><option value="fellowship">Fellowship</option><option value="award">Award</option><option value="student_aid">Student aid</option></select></div>'
            . '<div><label for="amount_mode">Amount rule</label><select id="amount_mode" name="amount_mode">'
            . '<option value="fixed_per_award">Fixed per award</option>'
            . '<option value="flexible_within_total">Flexible within annual total</option>'
            . '<option value="equal_among_recipients">Equal among recipients</option>'
            . '<option value="manual_rule">Manual donor rule</option></select></div>'
            . '<div><label><input type="checkbox" name="renewable" value="1"> Renewable</label>'
            . '<label for="max_total_award_years">Maximum total award years (optional)</label><input id="max_total_award_years" name="max_total_award_years" type="number" min="1"></div>'
            . '<div><label><input type="checkbox" name="student_teaching_required" value="1"> Student-teaching scholarship</label></div>'
            . '<div><label><input type="checkbox" name="single_semester_allowed_if_graduating" value="1" checked> Allow one-semester distribution when graduating</label></div>'
            . '</div>'
            . '<label for="intent">Original donor intent</label><textarea id="intent" name="original_intent_text" required></textarea>'
            . '<label for="summary">Structured summary</label><textarea id="summary" name="structured_summary"></textarea>'
            . '<label for="source">Source reference</label><input id="source" name="source_reference" placeholder="Gift agreement, fund description, etc.">'
            . '<label for="amount_rule_text">Manual amount rule (optional)</label><textarea id="amount_rule_text" name="manual_amount_rule_text"></textarea>'
            . '<label for="distribution_rule_text">Manual distribution rule (optional)</label><textarea id="distribution_rule_text" name="manual_distribution_rule_text"></textarea>'
            . '<div class="form-actions"><button type="submit">Create scholarship</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Catalog</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Scholarship</th><th>UICA</th><th>MFK</th><th>Renewable</th><th>Amount rule</th><th>Cycles</th><th>Status</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';

        return $this->render('Scholarship catalog',$body,$cycle);
    }

    public function create(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->currentCycle();

        try {
            $uica=trim((string)($_POST['uica_account_number']??''));
            $mfk=trim((string)($_POST['mfk']??''));
            $name=trim((string)($_POST['name']??''));
            $awardType=(string)($_POST['award_type']??'scholarship');
            $intent=trim((string)($_POST['original_intent_text']??''));
            $summary=trim((string)($_POST['structured_summary']??''));
            $source=trim((string)($_POST['source_reference']??''));
            $amountMode=(string)($_POST['amount_mode']??'fixed_per_award');

            if($uica===''||$name===''||$intent===''){
                throw new RuntimeException('UICA account number, scholarship name, and original donor intent are required.');
            }

            if(!in_array($awardType,['scholarship','fellowship','award','student_aid'],true)){
                throw new RuntimeException('Invalid award type.');
            }
            if(!in_array($amountMode,['fixed_per_award','flexible_within_total','equal_among_recipients','manual_rule'],true)){
                throw new RuntimeException('Invalid amount rule.');
            }

            $this->pdo->beginTransaction();

            $insert=$this->pdo->prepare(
                'INSERT INTO scholarships (public_id,uica_account_number,mfk,name,award_type,active)
                 VALUES (UUID(),?,?,?,?,1)'
            );
            $insert->execute([$uica,$mfk?:null,$name,$awardType]);
            $scholarshipId=(int)$this->pdo->lastInsertId();

            $intentStmt=$this->pdo->prepare(
                'INSERT INTO scholarship_intent_versions (
                    scholarship_id,version_number,original_intent_text,structured_summary,
                    source_reference,approved_at,active
                 ) VALUES (?,1,?,?,?,?,1)'
            );
            $intentStmt->execute([
                $scholarshipId,$intent,$summary?:null,$source?:null,date('Y-m-d H:i:s')
            ]);
            $intentId=(int)$this->pdo->lastInsertId();

            $this->pdo->prepare('UPDATE scholarships SET current_intent_version_id=? WHERE id=?')
                ->execute([$intentId,$scholarshipId]);

            $rules=$this->pdo->prepare(
                'INSERT INTO scholarship_award_rules (
                    intent_version_id,renewable,max_total_award_years,
                    renewal_requires_current_criteria,amount_mode,
                    student_teaching_required,single_semester_allowed_if_graduating,
                    manual_amount_rule_text,manual_distribution_rule_text
                 ) VALUES (?,?,?,1,?,?,?,?,?)'
            );
            $rules->execute([
                $intentId,
                isset($_POST['renewable'])?1:0,
                $this->nullableInt($_POST['max_total_award_years']??null),
                $amountMode,
                isset($_POST['student_teaching_required'])?1:0,
                isset($_POST['single_semester_allowed_if_graduating'])?1:0,
                $this->nullableText($_POST['manual_amount_rule_text']??null),
                $this->nullableText($_POST['manual_distribution_rule_text']??null),
            ]);

            $this->pdo->commit();

            $this->audit(
                'scholarship.created','scholarship',$scholarshipId,$cycle?(int)$cycle['id']:null,
                null,['uica_account_number'=>$uica,'name'=>$name,'intent_version_id'=>$intentId]
            );
            Flash::success('Scholarship created. Add structured criteria, then add it to an academic cycle.');
            $this->redirect('/admin/scholarships/'.$scholarshipId);
        } catch (\Throwable $e) {
            if($this->pdo->inTransaction()){$this->pdo->rollBack();}
            Flash::error($e->getMessage());
            $this->redirect('/admin/scholarships');
        }
    }

    public function detail(array $params): string
    {
        $this->requireAdmin();
        $cycle=$this->currentCycle();
        $id=(int)($params['id']??0);

        $stmt=$this->pdo->prepare(
            "SELECT s.*, siv.id AS intent_id, siv.version_number, siv.original_intent_text,
                    siv.structured_summary, siv.source_reference, siv.approved_at,
                    sar.renewable, sar.max_total_award_years, sar.amount_mode,
                    sar.student_teaching_required, sar.single_semester_allowed_if_graduating,
                    sar.manual_amount_rule_text, sar.manual_distribution_rule_text
             FROM scholarships s
             JOIN scholarship_intent_versions siv ON siv.id=s.current_intent_version_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id=siv.id
             WHERE s.id=?"
        );
        $stmt->execute([$id]);
        $scholarship=$stmt->fetch();

        if(!$scholarship){
            http_response_code(404);
            return $this->render('Scholarship not found','<div class="error">Scholarship not found.</div>',$cycle);
        }

        $lockedStmt=$this->pdo->prepare(
            'SELECT COUNT(*) FROM cycle_scholarships WHERE intent_version_id=?'
        );
        $lockedStmt->execute([$scholarship['intent_id']]);
        $locked=(int)$lockedStmt->fetchColumn()>0;

        $criteriaStmt=$this->pdo->prepare(
            'SELECT * FROM scholarship_criteria WHERE intent_version_id=? ORDER BY sort_order,id'
        );
        $criteriaStmt->execute([$scholarship['intent_id']]);
        $criteriaRows='';
        foreach($criteriaStmt->fetchAll() as $criterion){
            $value=$criterion['comparison_value_json']!==null
                ? json_decode((string)$criterion['comparison_value_json'],true)
                : null;
            $displayValue=is_array($value)?implode(', ',array_map('strval',$value)):(string)($value??'');
            $criteriaRows.='<tr>'
                .'<td>'.View::e(ucwords(str_replace('_',' ',$criterion['criterion_kind']))).'</td>'
                .'<td>'.View::e($criterion['display_label']).'</td>'
                .'<td>'.View::e($criterion['display_requirement']).'</td>'
                .'<td>'.View::e($criterion['field_key']??'Manual').'</td>'
                .'<td>'.View::e($criterion['operator']??'—').' '.View::e($displayValue).'</td>'
                .'<td>'.((int)$criterion['auto_evaluable']===1?'Automatic':'Display only').'</td>'
                .'</tr>';
        }
        if($criteriaRows===''){
            $criteriaRows='<tr><td colspan="6">No structured criteria have been added.</td></tr>';
        }

        $criterionForm=$locked
            ? '<div class="notice">This donor-intent version is already referenced by an academic cycle, so its criteria are immutable. Create a new intent version if donor documentation changes.</div>'
            : $this->criterionForm($id);

        $cyclePanel='';
        if($cycle){
            $cycleStmt=$this->pdo->prepare(
                'SELECT id,total_authorized_amount,planning_status FROM cycle_scholarships
                 WHERE cycle_id=? AND scholarship_id=?'
            );
            $cycleStmt->execute([(int)$cycle['id'],$id]);
            $cyclePlan=$cycleStmt->fetch();

            if($cyclePlan){
                $cyclePanel='<div class="notice">Included in <strong>'.View::e($cycle['label']).'</strong> with annual total '
                    .View::money($cyclePlan['total_authorized_amount']).' · '.View::status($cyclePlan['planning_status'])
                    .' <a href="/admin/planning/'.(int)$cyclePlan['id'].'">Open annual plan</a></div>';
            } else {
                $cyclePanel='<section class="card" style="margin-top:1rem"><h2>Add to current cycle</h2>'
                    .'<form method="post" action="/admin/scholarships/'.$id.'/add-to-cycle">'.View::csrfField()
                    .'<label for="annual_total">Initial annual authorized amount</label>'
                    .'<input id="annual_total" name="total_authorized_amount" type="number" min="0" step="0.01" value="0" required>'
                    .'<p class="muted">You can refine award count, amount, and allocations on the annual planning screen.</p>'
                    .'<button type="submit">Add to '.View::e($cycle['label']).'</button></form></section>';
            }
        }

        $body=Flash::render()
            .'<div class="page-header"><div><h1>'.View::e($scholarship['name']).'</h1>'
            .'<p>UICA '.View::e($scholarship['uica_account_number'])
            .($scholarship['mfk']?' · MFK '.View::e($scholarship['mfk']):'')
            .' · Intent version '.(int)$scholarship['version_number'].'</p></div>'
            .'<a class="button secondary" href="/admin/scholarships">Catalog</a></div>'
            .$cyclePanel
            .'<div class="grid">'
            .'<section class="stat"><span>Renewable</span><strong style="font-size:1.1rem">'.((int)$scholarship['renewable']===1?'Yes':'No').'</strong></section>'
            .'<section class="stat"><span>Amount rule</span><strong style="font-size:1.1rem">'.View::e(ucwords(str_replace('_',' ',$scholarship['amount_mode']))).'</strong></section>'
            .'<section class="stat"><span>Student teaching</span><strong style="font-size:1.1rem">'.((int)$scholarship['student_teaching_required']===1?'Required':'No').'</strong></section>'
            .'</div>'
            .'<section class="card" style="margin-top:1rem"><h2>Original donor intent</h2><p>'.nl2br(View::e($scholarship['original_intent_text'])).'</p>'
            .($scholarship['structured_summary']?'<h3>Structured summary</h3><p>'.nl2br(View::e($scholarship['structured_summary'])).'</p>':'')
            .($scholarship['source_reference']?'<p><strong>Source:</strong> '.View::e($scholarship['source_reference']).'</p>':'')
            .'</section>'
            .'<section class="card" style="margin-top:1rem"><h2>Structured criteria</h2><div class="table-wrap"><table>'
            .'<thead><tr><th>Kind</th><th>Label</th><th>Requirement</th><th>Field</th><th>Comparison</th><th>Evaluation</th></tr></thead>'
            .'<tbody>'.$criteriaRows.'</tbody></table></div></section>'
            .$criterionForm
            .$this->newVersionForm($id,$scholarship);

        return $this->render($scholarship['name'],$body,$cycle);
    }

    public function addCriterion(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $id=(int)($params['id']??0);

        try{
            $intent=$this->currentIntent($id);
            if($this->intentLocked((int)$intent['id'])){
                throw new RuntimeException('This donor-intent version is already in use and cannot be modified.');
            }

            $kind=(string)($_POST['criterion_kind']??'required');
            $field=trim((string)($_POST['field_key']??''));
            $operator=trim((string)($_POST['operator']??''));
            $rawValue=trim((string)($_POST['comparison_value']??''));
            $label=trim((string)($_POST['display_label']??''));
            $requirement=trim((string)($_POST['display_requirement']??''));
            $auto=isset($_POST['auto_evaluable'])?1:0;

            if(!in_array($kind,['required','preferred','preference_fallback','display_only'],true)||$label===''||$requirement===''){
                throw new RuntimeException('Criterion kind, label, and requirement are required.');
            }

            $value=null;
            if($rawValue!==''){
                $value=in_array($operator,['in','not_in','program_in'],true)
                    ? array_values(array_filter(array_map('trim',explode(',',$rawValue)),static fn($v)=>$v!==''))
                    : $rawValue;
            }

            $sortStmt=$this->pdo->prepare(
                'SELECT COALESCE(MAX(sort_order),0)+10 FROM scholarship_criteria WHERE intent_version_id=?'
            );
            $sortStmt->execute([(int)$intent['id']]);
            $sortOrder=(int)$sortStmt->fetchColumn();

            $stmt=$this->pdo->prepare(
                'INSERT INTO scholarship_criteria (
                    intent_version_id,criterion_kind,source_stage,field_key,operator,
                    comparison_value_json,auto_evaluable,display_label,display_requirement,sort_order
                 ) VALUES (?,?,"application",?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $intent['id'],$kind,$field?:null,$operator?:null,
                $value===null?null:json_encode($value,JSON_THROW_ON_ERROR),
                $auto,$label,$requirement,$sortOrder
            ]);

            Flash::success('Structured criterion added.');
        }catch(\Throwable $e){
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/scholarships/'.$id);
    }

    public function addToCycle(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle=$this->requireCurrentCycle();
        $id=(int)($params['id']??0);

        try{
            $intent=$this->currentIntent($id);
            $amount=round((float)($_POST['total_authorized_amount']??0),2);
            if($amount<0){throw new RuntimeException('Annual amount cannot be negative.');}

            $stmt=$this->pdo->prepare(
                "INSERT INTO cycle_scholarships (
                    cycle_id,scholarship_id,intent_version_id,total_authorized_amount,planning_status
                 ) VALUES (?,?,?,?,'draft')"
            );
            $stmt->execute([(int)$cycle['id'],$id,(int)$intent['id'],$amount]);
            $cycleScholarshipId=(int)$this->pdo->lastInsertId();

            $this->audit('scholarship.added_to_cycle','cycle_scholarship',$cycleScholarshipId,(int)$cycle['id'],null,[
                'scholarship_id'=>$id,'total_authorized_amount'=>$amount
            ]);

            Flash::success('Scholarship added to the current cycle.');
        }catch(\Throwable $e){
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/scholarships/'.$id);
    }

    public function newVersion(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $id=(int)($params['id']??0);

        try{
            $current=$this->currentIntent($id);
            $intent=trim((string)($_POST['original_intent_text']??''));
            $summary=trim((string)($_POST['structured_summary']??''));
            $source=trim((string)($_POST['source_reference']??''));

            if($intent===''){
                throw new RuntimeException('Original donor intent is required for a new version.');
            }

            $versionStmt=$this->pdo->prepare(
                'SELECT COALESCE(MAX(version_number),0)+1 FROM scholarship_intent_versions WHERE scholarship_id=?'
            );
            $versionStmt->execute([$id]);
            $version=(int)$versionStmt->fetchColumn();

            $this->pdo->beginTransaction();

            $insert=$this->pdo->prepare(
                'INSERT INTO scholarship_intent_versions (
                    scholarship_id,version_number,original_intent_text,structured_summary,
                    source_reference,approved_at,active
                 ) VALUES (?,?,?,?,?,NOW(),1)'
            );
            $insert->execute([$id,$version,$intent,$summary?:null,$source?:null]);
            $newIntentId=(int)$this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO scholarship_award_rules (
                    intent_version_id,renewable,max_total_award_years,
                    renewal_requires_current_criteria,amount_mode,student_teaching_required,
                    single_semester_allowed_if_graduating,manual_amount_rule_text,
                    manual_distribution_rule_text
                 )
                 SELECT ?,renewable,max_total_award_years,renewal_requires_current_criteria,
                        amount_mode,student_teaching_required,single_semester_allowed_if_graduating,
                        manual_amount_rule_text,manual_distribution_rule_text
                 FROM scholarship_award_rules WHERE intent_version_id=?'
            )->execute([$newIntentId,$current['id']]);

            $this->pdo->prepare(
                'INSERT INTO scholarship_criteria (
                    intent_version_id,criterion_kind,source_stage,field_key,operator,
                    comparison_value_json,priority_rank,auto_evaluable,display_label,
                    display_requirement,sort_order
                 )
                 SELECT ?,criterion_kind,source_stage,field_key,operator,
                        comparison_value_json,priority_rank,auto_evaluable,display_label,
                        display_requirement,sort_order
                 FROM scholarship_criteria WHERE intent_version_id=?'
            )->execute([$newIntentId,$current['id']]);

            $this->pdo->prepare(
                'UPDATE scholarship_intent_versions SET active=0 WHERE scholarship_id=? AND id<>?'
            )->execute([$id,$newIntentId]);
            $this->pdo->prepare(
                'UPDATE scholarships SET current_intent_version_id=? WHERE id=?'
            )->execute([$newIntentId,$id]);

            $this->pdo->commit();

            Flash::success('New donor-intent version created. Existing cycles retain their original version.');
        }catch(\Throwable $e){
            if($this->pdo->inTransaction()){$this->pdo->rollBack();}
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/scholarships/'.$id);
    }

    private function criterionForm(int $id): string
    {
        return '<section class="card" style="margin-top:1rem"><h2>Add structured criterion</h2>'
            .'<form method="post" action="/admin/scholarships/'.$id.'/criteria">'.View::csrfField()
            .'<div class="form-grid">'
            .'<div><label for="criterion_kind">Kind</label><select id="criterion_kind" name="criterion_kind">'
            .'<option value="required">Required</option><option value="preferred">Preferred</option>'
            .'<option value="preference_fallback">Preference fallback</option><option value="display_only">Display only</option></select></div>'
            .'<div><label for="field_key">Application field</label><select id="field_key" name="field_key">'
            .'<option value="">Manual/display only</option><option value="gpa">GPA</option><option value="residency_state">Residency state</option>'
            .'<option value="residency_county">Residency county</option><option value="financial_need">Financial need</option>'
            .'<option value="first_generation">First generation</option><option value="classification">Classification</option>'
            .'<option value="academic_level">Academic level</option><option value="degree_objective">Degree objective</option>'
            .'<option value="program_id">Program ID</option></select></div>'
            .'<div><label for="operator">Operator</label><select id="operator" name="operator">'
            .'<option value="eq">Equals</option><option value="neq">Does not equal</option><option value="gte">At least</option>'
            .'<option value="lte">At most</option><option value="in">In list</option><option value="not_in">Not in list</option>'
            .'<option value="contains">Contains</option><option value="exists">Exists</option></select></div>'
            .'<div><label for="comparison_value">Comparison value</label><input id="comparison_value" name="comparison_value">'
            .'<p class="muted">For lists, separate values with commas.</p></div>'
            .'</div>'
            .'<label for="display_label">Short label</label><input id="display_label" name="display_label" required>'
            .'<label for="display_requirement">Requirement shown to reviewers</label><textarea id="display_requirement" name="display_requirement" required></textarea>'
            .'<label><input type="checkbox" name="auto_evaluable" value="1" checked> Evaluate automatically when source data is available</label>'
            .'<div class="form-actions"><button type="submit">Add criterion</button></div></form></section>';
    }

    private function newVersionForm(int $id,array $scholarship): string
    {
        return '<details style="margin-top:1rem"><summary>Create a new donor-intent version</summary>'
            .'<p>Use this only when donor documentation changes. Historical and current-cycle records keep the version they already reference.</p>'
            .'<form method="post" action="/admin/scholarships/'.$id.'/intent-version">'.View::csrfField()
            .'<label for="new_intent">New original donor intent</label><textarea id="new_intent" name="original_intent_text" required>'
            .View::e($scholarship['original_intent_text']).'</textarea>'
            .'<label for="new_summary">Structured summary</label><textarea id="new_summary" name="structured_summary">'
            .View::e($scholarship['structured_summary']??'').'</textarea>'
            .'<label for="new_source">Source reference</label><input id="new_source" name="source_reference" value="'.View::e($scholarship['source_reference']??'').'">'
            .'<div class="form-actions"><button type="submit">Create new immutable version</button></div></form></details>';
    }

    private function currentIntent(int $scholarshipId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT siv.* FROM scholarships s
             JOIN scholarship_intent_versions siv ON siv.id=s.current_intent_version_id
             WHERE s.id=?'
        );
        $stmt->execute([$scholarshipId]);
        $intent=$stmt->fetch();

        if(!$intent){
            throw new RuntimeException('Scholarship donor intent is not configured.');
        }

        return $intent;
    }

    private function intentLocked(int $intentId): bool
    {
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM cycle_scholarships WHERE intent_version_id=?');
        $stmt->execute([$intentId]);
        return (int)$stmt->fetchColumn()>0;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value=trim((string)$value);
        return $value===''?null:(int)$value;
    }

    private function nullableText(mixed $value): ?string
    {
        $value=trim((string)$value);
        return $value===''?null:$value;
    }
}
