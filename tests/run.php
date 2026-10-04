<?php

declare(strict_types=1);

use App\Services\Awards\DistributionService;
use App\Services\Eligibility\EligibilityService;

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$distribution = new DistributionService();

$split = $distribution->defaultDistributions(
    totalAmount: 1000.00,
    awardPeriod: 'academic_year',
    fallTermId: 1,
    springTermId: 2
);

$assert(count($split) === 2, 'Awards over $500 should split into two distributions.');
$assert((float) $split[0]['amount'] === 500.00, 'Fall split should be $500 for a $1,000 award.');
$assert((float) $split[1]['amount'] === 500.00, 'Spring split should be $500 for a $1,000 award.');

$small = $distribution->defaultDistributions(
    totalAmount: 500.00,
    awardPeriod: 'academic_year',
    fallTermId: 1,
    springTermId: 2
);

$assert(count($small) === 1, 'Awards of $500 should not split by default.');
$assert((int) $small[0]['academic_term_id'] === 1, 'Small academic-year awards should default to Fall.');

$studentTeaching = $distribution->defaultDistributions(
    totalAmount: 2500.00,
    awardPeriod: 'academic_year',
    fallTermId: 1,
    springTermId: 2,
    studentTeachingRequired: true,
    studentTeachingTermId: 2
);

$assert(count($studentTeaching) === 1, 'Student-teaching awards should have one distribution.');
$assert((int) $studentTeaching[0]['academic_term_id'] === 2, 'Student-teaching award should use the teaching term.');
$assert((float) $studentTeaching[0]['amount'] === 2500.00, 'Student-teaching award should place the full amount in one term.');

$eligibility = new EligibilityService();

$result = $eligibility->evaluate(
    [
        [
            'id' => 1,
            'criterion_kind' => 'required',
            'field_key' => 'gpa',
            'operator' => 'gte',
            'comparison_value' => 3.0,
            'auto_evaluable' => true,
            'display_label' => 'Minimum GPA',
            'display_requirement' => 'Minimum cumulative GPA of 3.0',
        ],
        [
            'id' => 2,
            'criterion_kind' => 'preferred',
            'field_key' => 'residency_state',
            'operator' => 'eq',
            'comparison_value' => 'Iowa',
            'auto_evaluable' => true,
            'display_label' => 'Iowa resident',
            'display_requirement' => 'Preference for Iowa residents',
        ],
    ],
    [
        'gpa' => 2.83,
        'residency_state' => 'Iowa',
    ]
);

$assert(
    $result['status'] === 'potentially_ineligible',
    'A failed required criterion should be potentially ineligible.'
);

$preferenceOnly = $eligibility->evaluate(
    [
        [
            'id' => 3,
            'criterion_kind' => 'preferred',
            'field_key' => 'residency_state',
            'operator' => 'eq',
            'comparison_value' => 'Iowa',
            'auto_evaluable' => true,
            'display_label' => 'Iowa resident',
            'display_requirement' => 'Preference for Iowa residents',
        ],
    ],
    ['residency_state' => 'Illinois']
);

$assert(
    $preferenceOnly['status'] === 'eligible_unmet_preference',
    'A failed preference should not make the student ineligible.'
);

if ($failures !== []) {
    fwrite(STDERR, "Self-test failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "All domain self-tests passed.\n");
