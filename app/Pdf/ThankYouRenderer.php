<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Storage\LocalFileStorage;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use RuntimeException;

final class ThankYouRenderer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage
    ) {
    }

    public function renderAndStore(int $userId, string $studentName, string $html, string $filename): int
    {
        $allowed = '<p><br><strong><b><em><i><ul><ol><li>';
        $clean = strip_tags($html, $allowed);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            '<!doctype html><html><head><meta charset="utf-8"><style>'
            . '@page{margin:.8in;}body{font-family:Arial,Helvetica,sans-serif;font-size:11pt;line-height:1.5;color:#111;}'
            . '</style></head><body>' . $clean
            . '<p style="margin-top:2rem">Sincerely,<br>' . htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</body></html>'
        );
        $dompdf->setPaper('letter');
        $dompdf->render();
        $pdf = $dompdf->output();

        if (!is_string($pdf) || $pdf === '') {
            throw new RuntimeException('Thank-you letter PDF could not be generated.');
        }

        $stored = $this->storage->storeGenerated($pdf, $filename, 'thank-you-letters', 'application/pdf');

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
}
