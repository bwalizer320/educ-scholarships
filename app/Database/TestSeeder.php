<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;
use RuntimeException;

final class TestSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): array
    {
        if ((Env::get('APP_ENV', 'development') ?? 'development') === 'production') {
            throw new RuntimeException('Test data cannot be loaded in production.');
        }

        $this->pdo->beginTransaction();

        try {
            $cycleId = $this->ensureCycle();
            $programId = $this->programId('School Counseling');
            $departmentId = $this->parentId($programId);
            $coordinatorId = $this->ensureUser(
                'Jordan Reviewer',
                'jordan.reviewer@example.test',
                'program_coordinator',
                'Scholarships123!'
            );
            $chairId = $this->ensureUser(
                'Taylor Chair',
                'taylor.chair@example.test',
                'department_chair',
                'Scholarships123!'
            );

            $this->ensureAssignment($coordinatorId, $programId, 'coordinator', $cycleId);
            $this->ensureAssignment($chairId, $departmentId, 'chair', $cycleId);

            $scholarships = [
                $this->ensureScholarship(
                    'TEST-UICA-1001',
                    'TEST-MFK-1001',
                    'Hawkeye Education Scholarship',
                    true,
                    'flexible_within_total',
                    false,
                    [
                        ['required','gpa','gte',3.0,'Minimum GPA','Minimum cumulative GPA of 3.00'],
                        ['preferred','residency_state','eq','Iowa','Iowa residency','Preference for Iowa residents'],
                    ]
                ),
                $this->ensureScholarship(
                    'TEST-UICA-1002',
                    'TEST-MFK-1002',
                    'Student Teaching Opportunity Scholarship',
                    false,
                    'fixed_per_award',
                    true,
                    [
                        ['required','program_id','eq',$programId,'Program','Must be enrolled in the assigned program'],
                    ]
                ),
            ];

            $cycleScholarships = [];
            foreach ($scholarships as $index => $scholarship) {
                $cycleScholarships[] = $this->ensureCycleScholarship(
                    $cycleId,
                    $scholarship['scholarship_id'],
                    $scholarship['intent_version_id'],
                    $index === 0 ? 10000.00 : 5000.00,
                    $index === 0 ? 5 : 2,
                    $index === 0 ? 2000.00 : 2500.00
                );
            }

            $reviewUnitId = $this->ensureReviewUnit($cycleId, $programId, $coordinatorId);
            $this->ensureAllocation($cycleScholarships[0], $programId, $coordinatorId, 8000.00, 4, 2000.00);
            $this->ensureAllocation($cycleScholarships[1], $programId, $coordinatorId, 5000.00, 2, 2500.00);
            $this->ensureRubric($cycleId, $programId);

            $students = [
                ['00000001','Avery','Johnson','avery.johnson@example.test',3.72,'Iowa',1],
                ['00000002','Morgan','Lee','morgan.lee@example.test',2.85,'Iowa',1],
                ['00000003','Riley','Garcia','riley.garcia@example.test',3.45,'Illinois',0],
            ];

            foreach ($students as $student) {
                $this->ensureApplication($cycleId, $programId, ...$student);
            }

            $this->pdo->commit();

            return [
                'cycle_id' => $cycleId,
                'review_unit_id' => $reviewUnitId,
                'program_id' => $programId,
                'scholarships' => count($scholarships),
                'students' => count($students),
                'coordinator_email' => 'jordan.reviewer@example.test',
                'test_password' => 'Scholarships123!',
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureCycle(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM academic_cycles WHERE label = '2027-28' LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->pdo->exec('UPDATE academic_cycles SET is_current = 0');
            $this->pdo->prepare('UPDATE academic_cycles SET is_current = 1 WHERE id = ?')->execute([$id]);
            return (int) $id;
        }

        $this->pdo->exec('UPDATE academic_cycles SET is_current = 0');
        $insert = $this->pdo->prepare(
            "INSERT INTO academic_cycles (
                public_id, label, start_year, end_year, status,
                program_review_due_at, thank_you_due_at, is_current
             ) VALUES (UUID(), '2027-28', 2027, 2028, 'setup',
                       '2028-03-15 17:00:00', '2028-06-01 23:59:00', 1)"
        );
        $insert->execute();
        $cycleId = (int) $this->pdo->lastInsertId();

        $term = $this->pdo->prepare(
            'INSERT INTO academic_terms
                (cycle_id, term_key, display_name, season, calendar_year, is_primary_enrollment_term)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $term->execute([$cycleId,'fall_2027','Fall 2027','fall',2027]);
        $term->execute([$cycleId,'spring_2028','Spring 2028','spring',2028]);

        $items = [
            'cycle_dates'=>'Cycle dates confirmed',
            'scholarship_catalog_reviewed'=>'Scholarship catalog reviewed',
            'donor_criteria_reviewed'=>'Donor criteria reviewed',
            'renewal_candidates_reviewed'=>'Renewal candidates reviewed',
            'annual_amounts_confirmed'=>'Annual scholarship amounts confirmed',
            'allocations_confirmed'=>'Program allocations confirmed',
            'rubrics_confirmed'=>'Program rubrics confirmed',
            'reviewers_confirmed'=>'Program reviewers confirmed',
            'letter_templates_confirmed'=>'Award letter templates confirmed',
            'email_template_confirmed'=>'Email templates confirmed',
            'thank_you_settings_confirmed'=>'Thank-you settings confirmed',
            'applicant_import_ready'=>'Applicant import ready',
        ];
        $check = $this->pdo->prepare(
            "INSERT INTO cycle_checklist_items
                (cycle_id,item_key,label,status,sort_order)
             VALUES (?,?,?,'not_started',?)"
        );
        $sort = 10;
        foreach ($items as $key=>$label) {
            $check->execute([$cycleId,$key,$label,$sort]);
            $sort += 10;
        }

        return $cycleId;
    }

    private function programId(string $name): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM org_units WHERE unit_type = 'program' AND name = ? LIMIT 1"
        );
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException("Program {$name} was not found. Run seed:base first.");
        }

        return (int) $id;
    }

    private function parentId(int $orgUnitId): int
    {
        $stmt = $this->pdo->prepare('SELECT parent_id FROM org_units WHERE id = ?');
        $stmt->execute([$orgUnitId]);
        return (int) $stmt->fetchColumn();
    }

    private function ensureUser(string $name, string $email, string $role, string $password): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        [$first,$last] = explode(' ', $name, 2);
        $insert = $this->pdo->prepare(
            'INSERT INTO users (
                public_id,person_type,staff_role,first_name,last_name,display_name,email,active
             ) VALUES (UUID(),"staff",?,?,?,?,?,1)'
        );
        $insert->execute([$role,$first,$last,$name,$email]);
        $userId = (int) $this->pdo->lastInsertId();

        $identity = $this->pdo->prepare(
            'INSERT INTO auth_identities (
                user_id,provider,provider_subject,password_hash,activated_at
             ) VALUES (?,"local",?,?,NOW())'
        );
        $identity->execute([$userId,strtolower($email),password_hash($password,PASSWORD_ARGON2ID)]);

        return $userId;
    }

    private function ensureAssignment(int $userId, int $orgId, string $type, int $cycleId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_unit_assignments
             WHERE user_id = ? AND org_unit_id = ? AND assignment_type = ? AND cycle_id = ? LIMIT 1'
        );
        $stmt->execute([$userId,$orgId,$type,$cycleId]);

        if (!$stmt->fetchColumn()) {
            $this->pdo->prepare(
                'INSERT INTO user_unit_assignments
                    (user_id,org_unit_id,assignment_type,cycle_id,active)
                 VALUES (?,?,?,?,1)'
            )->execute([$userId,$orgId,$type,$cycleId]);
        }
    }

    private function ensureScholarship(
        string $uica,
        string $mfk,
        string $name,
        bool $renewable,
        string $amountMode,
        bool $studentTeaching,
        array $criteria
    ): array {
        $stmt = $this->pdo->prepare('SELECT id,current_intent_version_id FROM scholarships WHERE uica_account_number = ?');
        $stmt->execute([$uica]);
        $existing = $stmt->fetch();

        if ($existing) {
            return [
                'scholarship_id'=>(int)$existing['id'],
                'intent_version_id'=>(int)$existing['current_intent_version_id'],
            ];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO scholarships (public_id,uica_account_number,mfk,name,award_type,active)
             VALUES (UUID(),?,?,?,"scholarship",1)'
        );
        $insert->execute([$uica,$mfk,$name]);
        $scholarshipId = (int)$this->pdo->lastInsertId();

        $intent = $this->pdo->prepare(
            'INSERT INTO scholarship_intent_versions (
                scholarship_id,version_number,original_intent_text,structured_summary,approved_at,active
             ) VALUES (?,1,?,?,NOW(),1)'
        );
        $intentText = $studentTeaching
            ? 'Test donor intent: support an eligible student during the student-teaching semester.'
            : 'Test donor intent: support qualified students in the College of Education.';
        $intent->execute([$scholarshipId,$intentText,'Testing-only structured donor intent.']);
        $intentId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE scholarships SET current_intent_version_id = ? WHERE id = ?')
            ->execute([$intentId,$scholarshipId]);

        $rules = $this->pdo->prepare(
            'INSERT INTO scholarship_award_rules (
                intent_version_id,renewable,amount_mode,student_teaching_required,
                single_semester_allowed_if_graduating
             ) VALUES (?,?,?,?,1)'
        );
        $rules->execute([$intentId,$renewable ? 1 : 0,$amountMode,$studentTeaching ? 1 : 0]);

        $criterion = $this->pdo->prepare(
            'INSERT INTO scholarship_criteria (
                intent_version_id,criterion_kind,source_stage,field_key,operator,
                comparison_value_json,auto_evaluable,display_label,display_requirement,sort_order
             ) VALUES (?,?,"application",?,?,?,1,?,?,?)'
        );
        $sort=10;
        foreach ($criteria as [$kind,$field,$operator,$value,$label,$requirement]) {
            $criterion->execute([
                $intentId,$kind,$field,$operator,
                json_encode($value,JSON_THROW_ON_ERROR),$label,$requirement,$sort
            ]);
            $sort += 10;
        }

        return ['scholarship_id'=>$scholarshipId,'intent_version_id'=>$intentId];
    }

    private function ensureCycleScholarship(
        int $cycleId,int $scholarshipId,int $intentId,float $total,int $count,float $suggested
    ): int {
        $stmt=$this->pdo->prepare('SELECT id FROM cycle_scholarships WHERE cycle_id=? AND scholarship_id=?');
        $stmt->execute([$cycleId,$scholarshipId]);
        $id=$stmt->fetchColumn();
        if($id!==false){return (int)$id;}

        $insert=$this->pdo->prepare(
            'INSERT INTO cycle_scholarships (
                cycle_id,scholarship_id,intent_version_id,total_authorized_amount,
                planned_new_award_count,suggested_new_award_amount,planning_status
             ) VALUES (?,?,?,?,?,?,"confirmed")'
        );
        $insert->execute([$cycleId,$scholarshipId,$intentId,$total,$count,$suggested]);
        return (int)$this->pdo->lastInsertId();
    }

    private function ensureReviewUnit(int $cycleId,int $programId,int $reviewerId): int
    {
        $stmt=$this->pdo->prepare('SELECT id FROM cycle_review_units WHERE cycle_id=? AND org_unit_id=?');
        $stmt->execute([$cycleId,$programId]);
        $id=$stmt->fetchColumn();
        if($id!==false){return (int)$id;}

        $insert=$this->pdo->prepare(
            'INSERT INTO cycle_review_units (
                cycle_id,org_unit_id,primary_reviewer_user_id,review_method,due_at,status
             ) VALUES (?,?,?,"rubric","2028-03-15 17:00:00","not_started")'
        );
        $insert->execute([$cycleId,$programId,$reviewerId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function ensureAllocation(
        int $cycleScholarshipId,int $programId,int $reviewerId,float $amount,int $count,float $suggested
    ): void {
        $stmt=$this->pdo->prepare('SELECT id FROM cycle_allocations WHERE cycle_scholarship_id=? AND org_unit_id=?');
        $stmt->execute([$cycleScholarshipId,$programId]);
        if($stmt->fetchColumn()){return;}

        $this->pdo->prepare(
            'INSERT INTO cycle_allocations (
                cycle_scholarship_id,org_unit_id,primary_reviewer_user_id,
                authorized_new_amount,planned_new_award_count,suggested_award_amount,status
             ) VALUES (?,?,?,?,?,?,"review_open")'
        )->execute([$cycleScholarshipId,$programId,$reviewerId,$amount,$count,$suggested]);
    }

    private function ensureRubric(int $cycleId,int $programId): void
    {
        $stmt=$this->pdo->prepare('SELECT id FROM rubrics WHERE cycle_id=? AND org_unit_id=?');
        $stmt->execute([$cycleId,$programId]);
        $id=$stmt->fetchColumn();
        if($id===false){
            $this->pdo->prepare(
                'INSERT INTO rubrics (cycle_id,org_unit_id,name,status)
                 VALUES (?,?,"Program Scholarship Rubric","confirmed")'
            )->execute([$cycleId,$programId]);
            $id=(int)$this->pdo->lastInsertId();
        }

        $count=$this->pdo->prepare('SELECT COUNT(*) FROM rubric_items WHERE rubric_id=?');
        $count->execute([$id]);
        if((int)$count->fetchColumn()>0){return;}

        $insert=$this->pdo->prepare(
            'INSERT INTO rubric_items (rubric_id,label,description,max_points,sort_order)
             VALUES (?,?,?,?,?)'
        );
        $insert->execute([$id,'Academic preparation','Review academic preparation for the program.',5,10]);
        $insert->execute([$id,'Application strength','Review the strength of the scholarship application.',5,20]);
    }

    private function ensureApplication(
        int $cycleId,int $programId,string $universityId,string $first,string $last,
        string $email,float $gpa,string $state,int $need
    ): void {
        $student=$this->pdo->prepare('SELECT id FROM students WHERE university_id=?');
        $student->execute([$universityId]);
        $studentId=$student->fetchColumn();

        if($studentId===false){
            $this->pdo->prepare(
                'INSERT INTO students (
                    public_id,university_id,first_name,last_name,display_name,email
                 ) VALUES (UUID(),?,?,?,?,?)'
            )->execute([$universityId,$first,$last,$first.' '.$last,$email]);
            $studentId=(int)$this->pdo->lastInsertId();
        }

        $import=$this->pdo->prepare(
            "SELECT id FROM application_imports
             WHERE cycle_id=? AND filename='test-seed.csv' LIMIT 1"
        );
        $import->execute([$cycleId]);
        $importId=$import->fetchColumn();

        if($importId===false){
            $admin=(int)$this->pdo->query(
                "SELECT id FROM users
                 WHERE person_type='staff' AND active=1
                 ORDER BY CASE staff_role
                    WHEN 'system_admin' THEN 1
                    WHEN 'deans_office_admin' THEN 2
                    WHEN 'program_coordinator' THEN 3
                    ELSE 4
                 END, id
                 LIMIT 1"
            )->fetchColumn();

            if ($admin < 1) {
                throw new RuntimeException('At least one active staff user is required to seed test applicants.');
            }
            $this->pdo->prepare(
                'INSERT INTO application_imports (
                    cycle_id,filename,mapping_profile,status,row_count,inserted_count,
                    imported_by_user_id,completed_at
                 ) VALUES (?,"test-seed.csv","test","completed",3,3,?,NOW())'
            )->execute([$cycleId,$admin]);
            $importId=(int)$this->pdo->lastInsertId();
        }

        $offering=$this->pdo->prepare('SELECT id FROM program_offerings WHERE org_unit_id=? ORDER BY id LIMIT 1');
        $offering->execute([$programId]);
        $offeringId=(int)$offering->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO applications (
                cycle_id,student_id,latest_import_id,org_unit_id,program_offering_id,
                academic_level,degree_objective,classification,gpa,residency_state,
                financial_need,application_values_json,application_responses_json,active
             ) VALUES (?,?,?, ?,?,"Graduate","MA","Graduate",?,?,?,?,"{}",1)
             ON DUPLICATE KEY UPDATE
                latest_import_id=VALUES(latest_import_id),org_unit_id=VALUES(org_unit_id),
                program_offering_id=VALUES(program_offering_id),gpa=VALUES(gpa),
                residency_state=VALUES(residency_state),financial_need=VALUES(financial_need),active=1'
        )->execute([
            $cycleId,$studentId,$importId,$programId,$offeringId,$gpa,$state,$need,
            json_encode(['gpa'=>$gpa,'residency_state'=>$state,'financial_need'=>$need,'program_id'=>$programId],JSON_THROW_ON_ERROR)
        ]);
    }
}
