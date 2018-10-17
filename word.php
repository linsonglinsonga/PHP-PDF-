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
        // 设置字体
        $pdf->SetFont('Arial', '', 10);
        // 设置位置
        $pdf->SetXY(50, 70);
        // 写入内容
        $pdf->Write (7, iconv("utf-8","gbk","皇播"));


    }

}
$pdf->Output(date('Y-m-d H-i-s').'word.pdf');


