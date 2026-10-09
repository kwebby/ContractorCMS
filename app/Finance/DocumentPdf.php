<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Finance;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class DocumentPdf
{
    public function invoice(array $invoice): string
    {
        abort_unless(isset($invoice['snapshot']), 422, 'Issue this invoice before downloading its PDF.');

        return $this->render('invoice', $invoice['id'], $invoice['snapshot']);
    }

    public function receipt(array $payment): string
    {
        abort_unless(($payment['status'] ?? '') === 'settled' && isset($payment['snapshot']), 422, 'No receipt is available for this payment.');

        return $this->render('receipt', $payment['id'], $payment['snapshot']);
    }

    public function payslip(array $run): string
    {
        abort_unless(($run['status'] ?? '') === 'published' && isset($run['snapshot']), 422, 'This payslip has not been published.');

        return $this->render('payslip', $run['id'], $run['snapshot']);
    }

    private function render(string $type, string $id, array $snapshot): string
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $id), 422);
        $path = 'documents/'.$type.'s/'.$id.'.pdf';
        if (! Storage::disk('local')->exists($path)) {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('chroot', storage_path('app/private/documents'));
            $dompdf = new Dompdf($options);
            $dompdf->setPaper(($snapshot['design']['paper'] ?? 'letter') === 'a4' ? 'a4' : 'letter');
            $dompdf->loadHtml(view('documents.'.$type, ['document' => $snapshot])->render(), 'UTF-8');
            $dompdf->render();
            Storage::disk('local')->put($path, $dompdf->output());
        }

        return Storage::disk('local')->path($path);
    }
}
