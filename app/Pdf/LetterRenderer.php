<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Storage\LocalFileStorage;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use RuntimeException;

final class LetterRenderer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage
    ) {
    }

    public function renderAndStore(
        int $userId,
        string $templateHtml,
        array $mergeData,
        string $filename
    ): int {
        $html = \App\Support\MergeTemplate::render($templateHtml, $mergeData, true);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->document($html));
        $dompdf->setPaper('letter');
        $dompdf->render();

        $pdf = $dompdf->output();

        if (!is_string($pdf) || $pdf === '') {
            throw new RuntimeException('Award letter PDF could not be generated.');
        }

        $stored = $this->storage->storeGenerated(
            $pdf,
            $filename,
            'award-letters',
            'application/pdf'
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO file_objects (
                public_id, storage_driver, storage_key, original_filename,
                mime_type, size_bytes, sha256, uploaded_by_user_id
             ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $stored['storage_driver'],
            $stored['storage_key'],
            $stored['original_filename'],
            $stored['mime_type'],
            $stored['size_bytes'],
            $stored['sha256'],
            $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function document(string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . '@page{margin:.75in;} body{font-family:Arial,Helvetica,sans-serif;font-size:11pt;line-height:1.45;color:#111;}'
            . 'h1,h2,h3{margin-top:0;} .letterhead{border-bottom:5px solid #ffcd00;padding-bottom:12px;margin-bottom:24px;}'
            . '.letterhead strong{font-size:18pt;} table{border-collapse:collapse;}'
            . '</style></head><body><div class="letterhead"><strong>University of Iowa College of Education</strong></div>'
            . $body . '</body></html>';
    }
}
