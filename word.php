<?php

require_once('./fpdf/fpdf.php');
require_once('./fpdi/fpdi.php');


//word_watermark
$pdf = new FPDI();
// 设置字体
$pdf->AddGBFont('Arial', '宋体');

// get the page count
$pageCount = $pdf->setSourceFile('word.pdf');

// iterate through all pages
for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++)
{

    // import a page
    $templateId = $pdf->importPage($pageNo);

    // get the size of the imported page
    $size = $pdf->getTemplateSize($templateId);

    // create a page (landscape or portrait depending on the imported page size)
    if ($size['w'] > $size['h']) $pdf->AddPage('L', array($size['w'], $size['h']));
    else $pdf->AddPage('P', array($size['w'], $size['h']));

    // use the imported page
    $pdf->useTemplate($templateId);

    if ($pageNo == 1) {

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetXY(50, 70);
        $pdf->Write (7, iconv("utf-8","gbk","皇播"));
        $pdf->SetXY(61, 78.5);
        $pdf->Write(7, '1231231231312');
        $pdf->SetXY(58, 85.5);
        $pdf->Write(7, '12312312313123');

    }

}
$pdf->Output(date('Y-m-d H-i-s').'word.pdf');


