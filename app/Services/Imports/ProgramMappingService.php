<?php

declare(strict_types=1);

namespace App\Services\Imports;

use PDO;

final class ProgramMappingService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function mapOfficialOffering(
        string $program,
        ?string $objective,
        ?string $subprogram,
        ?string $subprogramId = null
    ): ?array {
        if ($subprogramId !== null && $subprogramId !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT po.id AS program_offering_id, po.org_unit_id
                 FROM program_offerings po
                 WHERE po.pgms_program_descr = ?
                   AND po.pgms_objective_key = ?
                   AND po.pgms_sub_program_id = ?
                   AND po.active = 1
                 LIMIT 1"
            );
            $stmt->execute([$program, $objective ?? '', $subprogramId]);
            $match = $stmt->fetch();

            if ($match) {
                return $this->normalize($match);
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT po.id AS program_offering_id, po.org_unit_id
             FROM program_offerings po
             WHERE po.pgms_program_descr = ?
               AND po.pgms_objective_key = ?
               AND COALESCE(po.pgms_sub_program_descr, '') = ?
               AND po.active = 1
             LIMIT 1"
        );
        $stmt->execute([$program, $objective ?? '', $subprogram ?? '']);
        $match = $stmt->fetch();

        return $match ? $this->normalize($match) : null;
    }

    public function mapAlias(
        string $sourceSystem,
        string $program,
        ?string $objective = null,
        ?string $subprogram = null,
        ?string $subprogramId = null
    ): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT program_offering_id, org_unit_id
             FROM program_mapping_aliases
             WHERE source_system = ?
               AND source_program = ?
               AND COALESCE(source_objective, '') = ?
               AND COALESCE(source_subprogram, '') = ?
               AND COALESCE(source_subprogram_id, '') = ?
               AND active = 1
             LIMIT 1"
        );
        $stmt->execute([
            $sourceSystem,
            $program,
            $objective ?? '',
            $subprogram ?? '',
            $subprogramId ?? '',
        ]);
        $match = $stmt->fetch();

        return $match ? $this->normalize($match) : null;
    }

    private function normalize(array $match): array
    {
        return [
            'program_offering_id' => $match['program_offering_id'] !== null
                ? (int) $match['program_offering_id']
                : null,
            'org_unit_id' => (int) $match['org_unit_id'],
        ];
    }
}
