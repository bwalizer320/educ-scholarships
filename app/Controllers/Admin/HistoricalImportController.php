<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Imports\HistoricalAwardImportService;
use App\Services\Imports\TabularFileReader;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class HistoricalImportController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();

        $cycles=$this->pdo->query(
            'SELECT id,label,status FROM academic_cycles ORDER BY start_year DESC'
        )->fetchAll();

        $cycleOptions='<option value="">Select historical cycle</option>';
        foreach($cycles as $cycle){
            $cycleOptions.='<option value="'.(int)$cycle['id'].'">'.View::e($cycle['label']).'</option>';
        }

        $rows=$this->pdo->query(
            "SELECT hi.*, ac.label AS cycle_label, u.display_name AS imported_by
             FROM historical_imports hi
             JOIN academic_cycles ac ON ac.id=hi.cycle_id
             JOIN users u ON u.id=hi.imported_by_user_id
             ORDER BY hi.created_at DESC"
        )->fetchAll();

        $table='';
        foreach($rows as $row){
            $table.='<tr><td>'.View::e($row['cycle_label']).'</td>'
                .'<td>'.View::e($row['filename']).'</td>'
                .'<td>'.View::status($row['status']).'</td>'
                .'<td>'.(int)$row['imported_count'].'</td>'
                .'<td>'.(int)$row['error_count'].'</td>'
                .'<td>'.View::e($row['imported_by']).'</td>'
                .'<td><a class="button secondary small" href="/admin/history-imports/'.(int)$row['id'].'/map">Mapping</a></td></tr>';
        }
        if($table===''){
            $table='<tr><td colspan="7">No historical award files imported.</td></tr>';
        }

        $body=Flash::render()
            .'<div class="page-header"><div><h1>Historical award imports</h1>'
            .'<p>Load prior-year recipient history as structured data without inventing annual planning values that are not present in the source.</p></div></div>'
            .'<section class="card"><h2>Upload historical awards</h2>'
            .'<form method="post" action="/admin/history-imports" enctype="multipart/form-data">'
            .View::csrfField()
            .'<div class="form-grid"><div><label for="cycle_id">Academic cycle</label><select id="cycle_id" name="cycle_id" required>'.$cycleOptions.'</select></div>'
            .'<div><label for="history_file">Historical award file</label><input id="history_file" name="history_file" type="file" accept=".csv,.tsv,.txt,.xls,.xlsx" required></div></div>'
            .'<p class="muted">Historical awards are preserved separately from live award-processing records so missing prior-year planning details are not inferred.</p>'
            .'<div class="form-actions"><button type="submit">Upload and map</button></div></form></section>'
            .'<section class="card" style="margin-top:1rem"><h2>Import history</h2><div class="table-wrap"><table>'
            .'<thead><tr><th>Cycle</th><th>File</th><th>Status</th><th>Imported</th><th>Errors</th><th>Imported by</th><th>Action</th></tr></thead><tbody>'
            .$table.'</tbody></table></div></section>';

        return $this->render('Historical imports',$body,$this->currentCycle());
    }

    public function stage(): never
    {
        $this->requireAdmin();
        $this->requirePost();

        try{
            $cycleId=(int)($_POST['cycle_id']??0);
            $file=$_FILES['history_file']??null;

            if($cycleId<1){
                throw new RuntimeException('Choose an academic cycle.');
            }
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
                throw new RuntimeException('Choose a valid historical award file.');
            }

            $extension=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
            if(!in_array($extension,['csv','tsv','txt','xls','xlsx'],true)){
                throw new RuntimeException('Historical award file must be CSV, TSV, XLS, or XLSX.');
            }

            $result=$this->service()->stage(
                $cycleId,
                (int)$this->auth->userId(),
                (string)$file['tmp_name'],
                (string)$file['name']
            );

            Flash::success('Historical file uploaded. Map the source columns.');
            $this->redirect('/admin/history-imports/'.(int)$result['import_id'].'/map');
        }catch(\Throwable $e){
            Flash::error($e->getMessage());
            $this->redirect('/admin/history-imports');
        }
    }

    public function mapping(array $params): string
    {
        $this->requireAdmin();
        $id=(int)($params['id']??0);
        $data=$this->service()->preview($id);

        $fields=[
            'uica_account'=>['UICA account',true,['uica account','uica account number','uica']],
            'university_id'=>['University ID',true,['university id','student id','univ id']],
            'student_name'=>['Student name',true,['student name','name','standard full name']],
            'email'=>['Email',false,['email','email address']],
            'amount'=>['Award amount',true,['award amount','amount','amount awarded']],
            'program'=>['Program',false,['program','program name','major']],
            'award_period'=>['Award period',false,['award period','term','semester']],
            'award_origin'=>['Award type',false,['award type','renewal','new/renewal']],
        ];

        $mapping='';
        foreach($fields as $key=>[$label,$required,$candidates]){
            $selected=$this->guess($data['headers'],$candidates);
            $mapping.='<div><label for="map_'.$key.'">'.View::e($label).($required?' *':'').'</label>'
                .'<select id="map_'.$key.'" name="mapping['.$key.']"'.($required?' required':'').'>'
                .$this->options($data['headers'],$selected,!$required).'</select></div>';
        }

        $headers='';
        foreach($data['headers'] as $h){$headers.='<th>'.View::e($h).'</th>';}
        $rows='';
        foreach($data['preview'] as $row){
            $rows.='<tr>';
            foreach($data['headers'] as $h){
                $rows.='<td>'.View::e(mb_strimwidth((string)($row[$h]??''),0,70,'…')).'</td>';
            }
            $rows.='</tr>';
        }

        $body='<div class="page-header"><div><h1>Map historical award columns</h1><p>'.View::e($data['import']['filename']).'</p></div>'
            .'<a class="button secondary" href="/admin/history-imports">Back</a></div>'
            .'<section class="card"><form method="post" action="/admin/history-imports/'.$id.'/process">'.View::csrfField()
            .'<div class="form-grid">'.$mapping.'</div><div class="form-actions"><button type="submit">Import historical awards</button></div></form></section>'
            .'<section class="card" style="margin-top:1rem"><h2>Preview</h2><div class="table-wrap"><table><thead><tr>'.$headers.'</tr></thead><tbody>'.$rows.'</tbody></table></div></section>';

        return $this->render('Historical mapping',Flash::render().$body,$this->currentCycle());
    }

    public function process(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $id=(int)($params['id']??0);

        try{
            $mapping=$_POST['mapping']??[];
            if(!is_array($mapping)){
                throw new RuntimeException('Historical column mapping is invalid.');
            }

            $result=$this->service()->process($id,$mapping);
            Flash::success(
                $result['imported'].' historical award(s) imported'
                .($result['errors']?'; '.$result['errors'].' row error(s).':'.')
            );
        }catch(\Throwable $e){
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/history-imports');
    }

    private function service(): HistoricalAwardImportService
    {
        return new HistoricalAwardImportService(
            $this->pdo,
            new LocalFileStorage(),
            new TabularFileReader()
        );
    }

    private function guess(array $headers,array $candidates): ?string
    {
        foreach($headers as $header){
            foreach($candidates as $candidate){
                if(strtolower(trim($header))===strtolower($candidate)){
                    return $header;
                }
            }
        }
        return null;
    }

    private function options(array $headers,?string $selected,bool $blank): string
    {
        $html=$blank?'<option value="">Not mapped</option>':'<option value="">Select column</option>';
        foreach($headers as $header){
            $html.='<option value="'.View::e($header).'"'.($selected===$header?' selected':'').'>'.View::e($header).'</option>';
        }
        return $html;
    }
}
