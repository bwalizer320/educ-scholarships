<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Storage\LocalFileStorage;
use PDO;
use RuntimeException;

final class HistoricalAwardImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage,
        private readonly TabularFileReader $reader
    ) {
    }

    public function stage(
        int $cycleId,
        int $userId,
        string $tmpPath,
        string $originalFilename
    ): array {
        $stored = $this->storage->storeUploaded($tmpPath, $originalFilename, 'historical-awards');

        $this->pdo->beginTransaction();
        try {
            $fileStmt = $this->pdo->prepare(
                'INSERT INTO file_objects (
                    public_id, storage_driver, storage_key, original_filename,
                    mime_type, size_bytes, sha256, uploaded_by_user_id
                 ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $fileStmt->execute([
                $stored['storage_driver'],
                $stored['storage_key'],
                $stored['original_filename'],
                $stored['mime_type'],
                $stored['size_bytes'],
                $stored['sha256'],
                $userId,
            ]);
            $fileId = (int)$this->pdo->lastInsertId();

            $headers = $this->reader->headers($stored['path'], $originalFilename);
            if ($headers === []) {
                throw new RuntimeException('No columns were found in the historical award file.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO historical_imports (
                    cycle_id, file_id, filename, status, imported_by_user_id
                 ) VALUES (?, ?, ?, 'uploaded', ?)"
            );
            $insert->execute([$cycleId,$fileId,$originalFilename,$userId]);
            $importId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();

            return [
                'import_id'=>$importId,
                'headers'=>$headers,
                'preview'=>iterator_to_array($this->reader->rows($stored['path'],$originalFilename,5)),
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function preview(int $importId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT hi.*, fo.storage_key
             FROM historical_imports hi
             JOIN file_objects fo ON fo.id=hi.file_id
             WHERE hi.id=?'
        );
        $stmt->execute([$importId]);
        $import=$stmt->fetch();

        if(!$import){
            throw new RuntimeException('Historical import not found.');
        }

        $path=$this->storage->path($import['storage_key']);

        return [
            'import'=>$import,
            'headers'=>$this->reader->headers($path,$import['filename']),
            'preview'=>iterator_to_array($this->reader->rows($path,$import['filename'],5)),
        ];
    }

    public function process(int $importId,array $mapping): array
    {
        foreach(['uica_account','university_id','student_name','amount'] as $required){
            if(empty($mapping[$required])){
                throw new RuntimeException('Map UICA account, University ID, student name, and award amount.');
            }
        }

        $data=$this->preview($importId);
        $import=$data['import'];
        $path=$this->storage->path($import['storage_key']);

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM historical_awards WHERE historical_import_id=?')
                ->execute([$importId]);

            $imported=0;
            $errors=0;
            $errorMessages=[];
            $rowCount=0;

            foreach($this->reader->rows($path,$import['filename']) as $index=>$row){
                $rowCount++;
                try{
                    $uica=$this->value($row,$mapping['uica_account']??null);
                    $universityId=$this->normalizeUniversityId($this->value($row,$mapping['university_id']??null));
                    $name=$this->value($row,$mapping['student_name']??null);
                    $email=$this->value($row,$mapping['email']??null);
                    $amount=$this->money($this->value($row,$mapping['amount']??null));
                    $period=$this->normalizePeriod($this->value($row,$mapping['award_period']??null));
                    $origin=$this->normalizeOrigin($this->value($row,$mapping['award_origin']??null));

                    if($uica===''||$universityId===''||$name===''||$amount<=0){
                        throw new RuntimeException('Required historical award values are missing.');
                    }

                    $scholarshipStmt=$this->pdo->prepare(
                        'SELECT id FROM scholarships WHERE uica_account_number=? LIMIT 1'
                    );
                    $scholarshipStmt->execute([$uica]);
                    $scholarshipId=$scholarshipStmt->fetchColumn();
                    if($scholarshipId===false){
                        throw new RuntimeException("Unknown UICA account {$uica}.");
                    }

                    $studentId=$this->upsertStudent($universityId,$name,$email);
                    $orgUnitId=$this->resolveProgram(
                        $this->value($row,$mapping['program']??null)
                    );

                    $insert=$this->pdo->prepare(
                        'INSERT INTO historical_awards (
                            historical_import_id,source_row_number,cycle_id,scholarship_id,
                            student_id,org_unit_id,award_amount,award_period,award_origin,
                            source_values_json
                         ) VALUES (?,?,?,?,?,?,?,?,?,?)'
                    );
                    $insert->execute([
                        $importId,
                        $index+2,
                        $import['cycle_id'],
                        $scholarshipId,
                        $studentId,
                        $orgUnitId,
                        $amount,
                        $period,
                        $origin,
                        json_encode($row,JSON_THROW_ON_ERROR),
                    ]);
                    $imported++;
                }catch(\Throwable $e){
                    $errors++;
                    if(count($errorMessages)<20){
                        $errorMessages[]='Row '.($index+2).': '.$e->getMessage();
                    }
                }
            }

            $status=$errors>0&&$imported===0?'failed':'completed';
            $this->pdo->prepare(
                'UPDATE historical_imports
                 SET column_mapping_json=?,status=?,row_count=?,imported_count=?,
                     error_count=?,error_summary=?,completed_at=CASE WHEN ?="completed" THEN NOW() ELSE NULL END
                 WHERE id=?'
            )->execute([
                json_encode($mapping,JSON_THROW_ON_ERROR),
                $status,
                $rowCount,
                $imported,
                $errors,
                $errorMessages?implode("\n",$errorMessages):null,
                $status,
                $importId,
            ]);

            $this->pdo->commit();

            return compact('status','rowCount','imported','errors');
        }catch(\Throwable $e){
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function upsertStudent(string $universityId,string $name,string $email): int
    {
        $stmt=$this->pdo->prepare('SELECT id FROM students WHERE university_id=? LIMIT 1');
        $stmt->execute([$universityId]);
        $id=$stmt->fetchColumn();

        [$first,$last]=$this->splitName($name);

        if($id!==false){
            $this->pdo->prepare(
                'UPDATE students SET display_name=?,first_name=?,last_name=?,
                    email=CASE WHEN ?<>"" THEN ? ELSE email END
                 WHERE id=?'
            )->execute([$name,$first,$last,$email,$email,$id]);
            return (int)$id;
        }

        $insert=$this->pdo->prepare(
            'INSERT INTO students (
                public_id,university_id,first_name,last_name,display_name,email
             ) VALUES (UUID(),?,?,?,?,?)'
        );
        $insert->execute([$universityId,$first,$last,$name,$email?:null]);

        return (int)$this->pdo->lastInsertId();
    }

    private function resolveProgram(string $program): ?int
    {
        $program=trim($program);
        if($program===''){
            return null;
        }

        $stmt=$this->pdo->prepare(
            "SELECT id FROM org_units
             WHERE active=1 AND unit_type='program' AND LOWER(name)=LOWER(?)
             LIMIT 1"
        );
        $stmt->execute([$program]);
        $id=$stmt->fetchColumn();

        return $id===false?null:(int)$id;
    }

    private function value(array $row,?string $header): string
    {
        return $header?trim((string)($row[$header]??'')):'';
    }

    private function money(string $value): float
    {
        $clean=preg_replace('/[^0-9.\-]/','',$value)??'';
        return $clean===''?0.0:round((float)$clean,2);
    }

    private function normalizeUniversityId(string $value): string
    {
        $value=trim($value);
        if(preg_match('/^="([^"]+)"$/',$value,$m)===1){
            return $m[1];
        }
        return trim($value,'"');
    }

    private function normalizePeriod(string $value): string
    {
        $value=strtolower(trim($value));
        return match(true){
            str_contains($value,'spring')=>'spring',
            str_contains($value,'fall')=>'fall',
            default=>'academic_year',
        };
    }

    private function normalizeOrigin(string $value): string
    {
        $value=strtolower(trim($value));
        return match(true){
            str_contains($value,'renew')=>'renewal',
            str_contains($value,'new')=>'new',
            default=>'unknown',
        };
    }

    private function splitName(string $name): array
    {
        if(str_contains($name,',')){
            [$last,$first]=array_map('trim',explode(',',$name,2));
            return [$first?:'Student',$last?:'Unknown'];
        }

        $parts=preg_split('/\s+/',trim($name))?:[];
        $first=array_shift($parts)?:'Student';
        $last=$parts===[]?'Unknown':array_pop($parts);

        return [$first,$last];
    }
}
