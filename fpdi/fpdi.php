<?php
//
//  FPDI - Version 1.5.2
//
//    Copyright 2004-2014 Setasign - Jan Slabon
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

require_once('fpdf_tpl.php');

/**
 * Class FPDI
 */
class FPDI extends FPDF_TPL
{
    /**
     * FPDI version
     *
     * @string
     */
    const VERSION = '1.5.2';

    /**
     * Actual filename
     *
     * @var string
     */
    public $currentFilename;

    /**
     * Parser-Objects
     *
     * @var fpdi_pdf_parser[]
     */
    public $parsers = array();
    
    /**
     * Current parser
     *
     * @var fpdi_pdf_parser
     */
    public $currentParser;

    /**
     * The name of the last imported page box
     *
     * @var string
     */
    public $lastUsedPageBox;

    /**
     * Object stack
     *
     * @var array
     */
    protected $_objStack;
    
    /**
     * Done object stack
     *
     * @var array
     */
    protected $_doneObjStack;

    /**
     * Current Object Id.
     *
     * @var integer
     */
    protected $_currentObjId;
    
    /**
     * Cache for imported pages/template ids
     *
     * @var array
     */
    protected $_importedPages = array();

    function AddCIDFont($family, $style, $name, $cw, $CMap, $registry)
    {
        $fontkey = strtolower($family).strtoupper($style);
        if(isset($this->fonts[$fontkey]))
            $this->Error("Font already added: $family $style");
        $i = count($this->fonts)+1;
        $name = str_replace(' ','',$name);
        $this->fonts[$fontkey] = array('i'=>$i, 'type'=>'Type0', 'name'=>$name, 'up'=>-130, 'ut'=>40, 'cw'=>$cw, 'CMap'=>$CMap, 'registry'=>$registry);
    }

    function AddCIDFonts($family, $name, $cw, $CMap, $registry)
    {
        $this->AddCIDFont($family,'',$name,$cw,$CMap,$registry);
        $this->AddCIDFont($family,'B',$name.',Bold',$cw,$CMap,$registry);
        $this->AddCIDFont($family,'I',$name.',Italic',$cw,$CMap,$registry);
        $this->AddCIDFont($family,'BI',$name.',BoldItalic',$cw,$CMap,$registry);
    }

    function AddBig5Font($family='Big5', $name='MSungStd-Light-Acro')
    {
        // Add Big5 font with proportional Latin
        $cw = $GLOBALS['Big5_widths'];
        $CMap = 'ETenms-B5-H';
        $registry = array('ordering'=>'CNS1', 'supplement'=>0);
        $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
    }

    function AddBig5hwFont($family='Big5-hw', $name='MSungStd-Light-Acro')
    {
        // Add Big5 font with half-witdh Latin
        for($i=32;$i<=126;$i++)
            $cw[chr($i)] = 500;
        $CMap = 'ETen-B5-H';
        $registry = array('ordering'=>'CNS1', 'supplement'=>0);
        $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
    }

    function AddGBFont($family='GB', $name='STSongStd-Light-Acro')
    {
        // Add GB font with proportional Latin
        $cw = $GLOBALS['GB_widths'];
        $CMap = 'GBKp-EUC-H';
        $registry = array('ordering'=>'GB1', 'supplement'=>2);
        $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
    }

    function AddGBhwFont($family='GB-hw', $name='STSongStd-Light-Acro')
    {
        // Add GB font with half-width Latin
        for($i=32;$i<=126;$i++)
            $cw[chr($i)] = 500;
        $CMap = 'GBK-EUC-H';
        $registry = array('ordering'=>'GB1', 'supplement'=>2);
        $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
    }

    function GetStringWidth($s)
    {
        if($this->CurrentFont['type']=='Type0')
            return $this->GetMBStringWidth($s);
        else
            return parent::GetStringWidth($s);
    }

    function GetMBStringWidth($s)
    {
        // Multi-byte version of GetStringWidth()
        $l = 0;
        $cw = &$this->CurrentFont['cw'];
        $nb = strlen($s);
        $i = 0;
        while($i<$nb)
        {
            $c = $s[$i];
            if(ord($c)<128)
            {
                $l += $cw[$c];
                $i++;
            }
            else
            {
                $l += 1000;
                $i += 2;
            }
        }
        return $l*$this->FontSize/1000;
    }

    function MultiCell($w, $h, $txt, $border=0, $align='L', $fill=0)
    {
        if($this->CurrentFont['type']=='Type0')
            $this->MBMultiCell($w,$h,$txt,$border,$align,$fill);
        else
            parent::MultiCell($w,$h,$txt,$border,$align,$fill);
    }

    function MBMultiCell($w, $h, $txt, $border=0, $align='L', $fill=0)
    {
        // Multi-byte version of MultiCell()
        $cw = &$this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',$txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $b = 0;
        if($border)
        {
            if($border==1)
            {
                $border = 'LTRB';
                $b = 'LRT';
                $b2 = 'LR';
            }
            else
            {
                $b2 = '';
                if(is_int(strpos($border,'L')))
                    $b2 .= 'L';
                if(is_int(strpos($border,'R')))
                    $b2 .= 'R';
                $b = is_int(strpos($border,'T')) ? $b2.'T' : $b2;
            }
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb)
        {
            // Get next character
            $c = $s[$i];
            // Check if ASCII or MB
            $ascii = (ord($c)<128);
            if($c=="\n")
            {
                // Explicit line break
                $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                if($border && $nl==2)
                    $b = $b2;
                continue;
            }
            if(!$ascii)
            {
                $sep = $i;
                $ls = $l;
            }
            elseif($c==' ')
            {
                $sep = $i;
                $ls = $l;
            }
            $l += $ascii ? $cw[$c] : 1000;
            if($l>$wmax)
            {
                // Automatic line break
                if($sep==-1 || $i==$j)
                {
                    if($i==$j)
                        $i += $ascii ? 1 : 2;
                    $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
                }
                else
                {
                    $this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
                    $i = ($s[$sep]==' ') ? $sep+1 : $sep;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                if($border && $nl==2)
                    $b = $b2;
            }
            else
                $i += $ascii ? 1 : 2;
        }
        // Last chunk
        if($border && is_int(strpos($border,'B')))
            $b .= 'B';
        $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
        $this->x = $this->lMargin;
    }

    function Write($h, $txt, $link='')
    {
        if($this->CurrentFont['type']=='Type0')
            $this->MBWrite($h,$txt,$link);
        else
            parent::Write($h,$txt,$link);
    }

    function MBWrite($h, $txt, $link)
    {
        // Multi-byte version of Write()
        $cw = &$this->CurrentFont['cw'];
        $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',$txt);
        $nb = strlen($s);
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb)
        {
            // Get next character
            $c = $s[$i];
            // Check if ASCII or MB
            $ascii = (ord($c)<128);
            if($c=="\n")
            {
                // Explicit line break
                $this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                if($nl==1)
                {
                    $this->x = $this->lMargin;
                    $w = $this->w-$this->rMargin-$this->x;
                    $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
                }
                $nl++;
                continue;
            }
            if(!$ascii || $c==' ')
                $sep = $i;
            $l += $ascii ? $cw[$c] : 1000;
            if($l>$wmax)
            {
                // Automatic line break
                if($sep==-1 || $i==$j)
                {
                    if($this->x>$this->lMargin)
                    {
                        // Move to next line
                        $this->x = $this->lMargin;
                        $this->y += $h;
                        $w = $this->w-$this->rMargin-$this->x;
                        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
                        $i++;
                        $nl++;
                        continue;
                    }
                    if($i==$j)
                        $i += $ascii ? 1 : 2;
                    $this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
                }
                else
                {
                    $this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',0,$link);
                    $i = ($s[$sep]==' ') ? $sep+1 : $sep;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                if($nl==1)
                {
                    $this->x = $this->lMargin;
                    $w = $this->w-$this->rMargin-$this->x;
                    $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
                }
                $nl++;
            }
            else
                $i += $ascii ? 1 : 2;
        }
        // Last chunk
        if($i!=$j)
            $this->Cell($l/1000*$this->FontSize,$h,substr($s,$j,$i-$j),0,0,'',0,$link);
    }

    function _putType0($font)
    {
        // Type0
        $this->_newobj();
        $this->_out('<</Type /Font');
        $this->_out('/Subtype /Type0');
        $this->_out('/BaseFont /'.$font['name'].'-'.$font['CMap']);
        $this->_out('/Encoding /'.$font['CMap']);
        $this->_out('/DescendantFonts ['.($this->n+1).' 0 R]');
        $this->_out('>>');
        $this->_out('endobj');
        // CIDFont
        $this->_newobj();
        $this->_out('<</Type /Font');
        $this->_out('/Subtype /CIDFontType0');
        $this->_out('/BaseFont /'.$font['name']);
        $this->_out('/CIDSystemInfo <</Registry '.$this->_textstring('Adobe').' /Ordering '.$this->_textstring($font['registry']['ordering']).' /Supplement '.$font['registry']['supplement'].'>>');
        $this->_out('/FontDescriptor '.($this->n+1).' 0 R');
        if($font['CMap']=='ETen-B5-H')
            $W = '13648 13742 500';
        elseif($font['CMap']=='GBK-EUC-H')
            $W = '814 907 500 7716 [500]';
        else
            $W = '1 ['.implode(' ',$font['cw']).']';
        $this->_out('/W ['.$W.']>>');
        $this->_out('endobj');
        // Font descriptor
        $this->_newobj();
        $this->_out('<</Type /FontDescriptor');
        $this->_out('/FontName /'.$font['name']);
        $this->_out('/Flags 6');
        $this->_out('/FontBBox [0 -200 1000 900]');
        $this->_out('/ItalicAngle 0');
        $this->_out('/Ascent 800');
        $this->_out('/Descent -200');
        $this->_out('/CapHeight 800');
        $this->_out('/StemV 50');
        $this->_out('>>');
        $this->_out('endobj');
    }
    
    /**
     * Set a source-file.
     *
     * Depending on the PDF version of the used document the PDF version of the resulting document will
     * be adjusted to the higher version.
     *
     * @param string $filename A valid path to the PDF document from which pages should be imported from
     * @return int The number of pages in the document
     */
    public function setSourceFile($filename)
    {
        $_filename = realpath($filename);
        if (false !== $_filename)
            $filename = $_filename;

        $this->currentFilename = $filename;
        
        if (!isset($this->parsers[$filename])) {
            $this->parsers[$filename] = $this->_getPdfParser($filename);
            $this->setPdfVersion(
                max($this->getPdfVersion(), $this->parsers[$filename]->getPdfVersion())
            );
        }

        $this->currentParser =& $this->parsers[$filename];
        
        return $this->parsers[$filename]->getPageCount();
    }
    
    /**
     * Returns a PDF parser object
     *
     * @param string $filename
     * @return fpdi_pdf_parser
     */
    protected function _getPdfParser($filename)
    {
        require_once('fpdi_pdf_parser.php');
    	return new fpdi_pdf_parser($filename);
    }
    
    /**
     * Get the current PDF version.
     *
     * @return string
     */
    public function getPdfVersion()
    {
		return $this->PDFVersion;
	}
    
    /**
     * Set the PDF version.
     *
     * @param string $version
     */
    public function setPdfVersion($version = '1.3')
    {
        $this->PDFVersion = sprintf('%.1F', $version);
    }
	
    /**
     * Import a page.
     *
     * The second parameter defines the bounding box that should be used to transform the page into a
     * form XObject.
     *
     * Following values are available: MediaBox, CropBox, BleedBox, TrimBox, ArtBox.
     * If a box is not especially defined its default box will be used:
     *
     * <ul>
     *   <li>CropBox: Default -> MediaBox</li>
     *   <li>BleedBox: Default -> CropBox</li>
     *   <li>TrimBox: Default -> CropBox</li>
     *   <li>ArtBox: Default -> CropBox</li>
     * </ul>
     *
     * It is possible to get the used page box by the {@link getLastUsedPageBox()} method.
     *
     * @param int $pageNo The page number
     * @param string $boxName The boundary box to use when transforming the page into a form XObject
     * @param boolean $groupXObject Define the form XObject as a group XObject to support transparency (if used)
     * @return int An id of the imported page/template to use with e.g. fpdf_tpl::useTemplate()
     * @throws LogicException|InvalidArgumentException
     * @see getLastUsedPageBox()
     */
    public function importPage($pageNo, $boxName = 'CropBox', $groupXObject = true)
    {
        if ($this->_inTpl) {
            throw new LogicException('Please import the desired pages before creating a new template.');
        }
        
        $fn = $this->currentFilename;
        $boxName = '/' . ltrim($boxName, '/');

        // check if page already imported
        $pageKey = $fn . '-' . ((int)$pageNo) . $boxName;
        if (isset($this->_importedPages[$pageKey])) {
            return $this->_importedPages[$pageKey];
        }
        
        $parser = $this->parsers[$fn];
        $parser->setPageNo($pageNo);

        if (!in_array($boxName, $parser->availableBoxes)) {
            throw new InvalidArgumentException(sprintf('Unknown box: %s', $boxName));
        }
            
        $pageBoxes = $parser->getPageBoxes($pageNo, $this->k);
        
        /**
         * MediaBox
         * CropBox: Default -> MediaBox
         * BleedBox: Default -> CropBox
         * TrimBox: Default -> CropBox
         * ArtBox: Default -> CropBox
         */
        if (!isset($pageBoxes[$boxName]) && ($boxName == '/BleedBox' || $boxName == '/TrimBox' || $boxName == '/ArtBox'))
            $boxName = '/CropBox';
        if (!isset($pageBoxes[$boxName]) && $boxName == '/CropBox')
            $boxName = '/MediaBox';
        
        if (!isset($pageBoxes[$boxName]))
            return false;
            
        $this->lastUsedPageBox = $boxName;
        
        $box = $pageBoxes[$boxName];
        
        $this->tpl++;
        $this->_tpls[$this->tpl] = array();
        $tpl =& $this->_tpls[$this->tpl];
        $tpl['parser'] = $parser;
        $tpl['resources'] = $parser->getPageResources();
        $tpl['buffer'] = $parser->getContent();
        $tpl['box'] = $box;
        $tpl['groupXObject'] = $groupXObject;
        if ($groupXObject) {
            $this->setPdfVersion(max($this->getPdfVersion(), 1.4));
        }

        // To build an array that can be used by PDF_TPL::useTemplate()
        $this->_tpls[$this->tpl] = array_merge($this->_tpls[$this->tpl], $box);
        
        // An imported page will start at 0,0 all the time. Translation will be set in _putformxobjects()
        $tpl['x'] = 0;
        $tpl['y'] = 0;
        
        // handle rotated pages
        $rotation = $parser->getPageRotation($pageNo);
        $tpl['_rotationAngle'] = 0;
        if (isset($rotation[1]) && ($angle = $rotation[1] % 360) != 0) {
        	$steps = $angle / 90;
                
            $_w = $tpl['w'];
            $_h = $tpl['h'];
            $tpl['w'] = $steps % 2 == 0 ? $_w : $_h;
            $tpl['h'] = $steps % 2 == 0 ? $_h : $_w;
            
            if ($angle < 0)
            	$angle += 360;
            
        	$tpl['_rotationAngle'] = $angle * -1;
        }
        
        $this->_importedPages[$pageKey] = $this->tpl;
        
        return $this->tpl;
    }
    
    /**
     * Returns the last used page boundary box.
     *
     * @return string The used boundary box: MediaBox, CropBox, BleedBox, TrimBox or ArtBox
     */
    public function getLastUsedPageBox()
    {
        return $this->lastUsedPageBox;
    }

    /**
     * Use a template or imported page in current page or other template.
     *
     * You can use a template in a page or in another template.
     * You can give the used template a new size. All parameters are optional.
     * The width or height is calculated automatically if one is given. If no
     * parameter is given the origin size as defined in beginTemplate() or of
     * the imported page is used.
     *
     * The calculated or used width and height are returned as an array.
     *
     * @param int $tplIdx A valid template-id
     * @param int $x The x-position
     * @param int $y The y-position
     * @param int $w The new width of the template
     * @param int $h The new height of the template
     * @param boolean $adjustPageSize If set to true the current page will be resized to fit the dimensions
     *                                of the template
     *
     * @return array The height and width of the template (array('w' => ..., 'h' => ...))
     * @throws LogicException|InvalidArgumentException
     */
    public function useTemplate($tplIdx, $x = null, $y = null, $w = 0, $h = 0, $adjustPageSize = false)
    {
        if ($adjustPageSize == true && is_null($x) && is_null($y)) {
            $size = $this->getTemplateSize($tplIdx, $w, $h);
            $orientation = $size['w'] > $size['h'] ? 'L' : 'P';
            $size = array($size['w'], $size['h']);
            
            if (is_subclass_of($this, 'TCPDF')) {
            	$this->setPageFormat($size, $orientation);
            } else {
            	$size = $this->_getpagesize($size);
            	
            	if($orientation != $this->CurOrientation ||
                    $size[0] != $this->CurPageSize[0] ||
                    $size[1] != $this->CurPageSize[1]
                ) {
					// New size or orientation
					if ($orientation=='P') {
						$this->w = $size[0];
						$this->h = $size[1];
					} else {
						$this->w = $size[1];
						$this->h = $size[0];
					}
					$this->wPt = $this->w * $this->k;
					$this->hPt = $this->h * $this->k;
					$this->PageBreakTrigger = $this->h - $this->bMargin;
					$this->CurOrientation = $orientation;
					$this->CurPageSize = $size;
					$this->PageSizes[$this->page] = array($this->wPt, $this->hPt);
				}
            } 
        }
        
        $this->_out('q 0 J 1 w 0 j 0 G 0 g'); // reset standard values
        $size = parent::useTemplate($tplIdx, $x, $y, $w, $h);
        $this->_out('Q');
        
        return $size;
    }
    
    /**
     * Copy all imported objects to the resulting document.
     */
    protected function _putimportedobjects()
    {
        foreach($this->parsers AS $filename => $p) {
            $this->currentParser =& $p;
            if (!isset($this->_objStack[$filename]) || !is_array($this->_objStack[$filename])) {
                continue;
            }
            while(($n = key($this->_objStack[$filename])) !== null) {
                try {
                    $nObj = $this->currentParser->resolveObject($this->_objStack[$filename][$n][1]);
                } catch (Exception $e) {
                    $nObj = array(pdf_parser::TYPE_OBJECT, pdf_parser::TYPE_NULL);
                }

                $this->_newobj($this->_objStack[$filename][$n][0]);

                if ($nObj[0] == pdf_parser::TYPE_STREAM) {
                    $this->_writeValue($nObj);
                } else {
                    $this->_writeValue($nObj[1]);
                }

                $this->_out("\nendobj");
                $this->_objStack[$filename][$n] = null; // free memory
                unset($this->_objStack[$filename][$n]);
                reset($this->_objStack[$filename]);
            }
        }
    }

    /**
     * Writes the form XObjects to the PDF document.
     */
    protected function _putformxobjects()
    {
        $filter = ($this->compress) ? '/Filter /FlateDecode ' : '';
	    reset($this->_tpls);
        foreach($this->_tpls AS $tplIdx => $tpl) {
            $this->_newobj();
    		$currentN = $this->n; // TCPDF/Protection: rem current "n"
    		
    		$this->_tpls[$tplIdx]['n'] = $this->n;
    		$this->_out('<<' . $filter . '/Type /XObject');
            $this->_out('/Subtype /Form');
            $this->_out('/FormType 1');
            
            $this->_out(sprintf('/BBox [%.2F %.2F %.2F %.2F]', 
                (isset($tpl['box']['llx']) ? $tpl['box']['llx'] : $tpl['x']) * $this->k,
                (isset($tpl['box']['lly']) ? $tpl['box']['lly'] : -$tpl['y']) * $this->k,
                (isset($tpl['box']['urx']) ? $tpl['box']['urx'] : $tpl['w'] + $tpl['x']) * $this->k,
                (isset($tpl['box']['ury']) ? $tpl['box']['ury'] : $tpl['h'] - $tpl['y']) * $this->k
            ));
            
            $c = 1;
            $s = 0;
            $tx = 0;
            $ty = 0;
            
            if (isset($tpl['box'])) {
                $tx = -$tpl['box']['llx'];
                $ty = -$tpl['box']['lly']; 
                
                if ($tpl['_rotationAngle'] <> 0) {
                    $angle = $tpl['_rotationAngle'] * M_PI/180;
                    $c = cos($angle);
                    $s = sin($angle);
                    
                    switch($tpl['_rotationAngle']) {
                        case -90:
                           $tx = -$tpl['box']['lly'];
                           $ty = $tpl['box']['urx'];
                           break;
                        case -180:
                            $tx = $tpl['box']['urx'];
                            $ty = $tpl['box']['ury'];
                            break;
                        case -270:
                        	$tx = $tpl['box']['ury'];
                            $ty = -$tpl['box']['llx'];
                            break;
                    }
                }
            } else if ($tpl['x'] != 0 || $tpl['y'] != 0) {
                $tx = -$tpl['x'] * 2;
                $ty = $tpl['y'] * 2;
            }
            
            $tx *= $this->k;
            $ty *= $this->k;
            
            if ($c != 1 || $s != 0 || $tx != 0 || $ty != 0) {
                $this->_out(sprintf('/Matrix [%.5F %.5F %.5F %.5F %.5F %.5F]',
                    $c, $s, -$s, $c, $tx, $ty
                ));
            }
            
            $this->_out('/Resources ');

            if (isset($tpl['resources'])) {
                $this->currentParser = $tpl['parser'];
                $this->_writeValue($tpl['resources']); // "n" will be changed
            } else {

                $this->_out('<</ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
                if (isset($this->_res['tpl'][$tplIdx])) {
                    $res = $this->_res['tpl'][$tplIdx];

                    if (isset($res['fonts']) && count($res['fonts'])) {
                        $this->_out('/Font <<');
                        foreach ($res['fonts'] as $font)
                            $this->_out('/F' . $font['i'] . ' ' . $font['n'] . ' 0 R');
                        $this->_out('>>');
                    }
                    if (isset($res['images']) && count($res['images']) ||
                       isset($res['tpls']) && count($res['tpls']))
                    {
                        $this->_out('/XObject <<');
                        if (isset($res['images'])) {
                            foreach ($res['images'] as $image)
                                $this->_out('/I' . $image['i'] . ' ' . $image['n'] . ' 0 R');
                        }
                        if (isset($res['tpls'])) {
                            foreach ($res['tpls'] as $i => $_tpl)
                                $this->_out($this->tplPrefix . $i . ' ' . $_tpl['n'] . ' 0 R');
                        }
                        $this->_out('>>');
                    }
                    $this->_out('>>');
                }
            }

            if (isset($tpl['groupXObject']) && $tpl['groupXObject']) {
                $this->_out('/Group <</Type/Group/S/Transparency>>');
            }

            $newN = $this->n; // TCPDF: rem new "n"
            $this->n = $currentN; // TCPDF: reset to current "n"

            $buffer = ($this->compress) ? gzcompress($tpl['buffer']) : $tpl['buffer'];

            if (is_subclass_of($this, 'TCPDF')) {
            	$buffer = $this->_getrawstream($buffer);
            	$this->_out('/Length ' . strlen($buffer) . ' >>');
            	$this->_out("stream\n" . $buffer . "\nendstream");
            } else {
	            $this->_out('/Length ' . strlen($buffer) . ' >>');
	    		$this->_putstream($buffer);
            }
    		$this->_out('endobj');
    		$this->n = $newN; // TCPDF: reset to new "n"
        }
        
        $this->_putimportedobjects();
    }

    /**
     * Creates and optionally write the object definition to the document.
     *
     * Rewritten to handle existing own defined objects
     *
     * @param bool $objId
     * @param bool $onlyNewObj
     * @return bool|int
     */
    public function _newobj($objId = false, $onlyNewObj = false)
    {
        if (!$objId) {
            $objId = ++$this->n;
        }

        //Begin a new object
        if (!$onlyNewObj) {
            $this->offsets[$objId] = is_subclass_of($this, 'TCPDF') ? $this->bufferlen : strlen($this->buffer);
            $this->_out($objId . ' 0 obj');
            $this->_currentObjId = $objId; // for later use with encryption
        }
        
        return $objId;
    }

    /**
     * Writes a PDF value to the resulting document.
     *
     * Needed to rebuild the source document
     *
     * @param mixed $value A PDF-Value. Structure of values see cases in this method
     */
    protected function _writeValue(&$value)
    {
        if (is_subclass_of($this, 'TCPDF')) {
            parent::_prepareValue($value);
        }
        
        switch ($value[0]) {

    		case pdf_parser::TYPE_TOKEN:
                $this->_straightOut($value[1] . ' ');
    			break;
		    case pdf_parser::TYPE_NUMERIC:
    		case pdf_parser::TYPE_REAL:
                if (is_float($value[1]) && $value[1] != 0) {
    			    $this->_straightOut(rtrim(rtrim(sprintf('%F', $value[1]), '0'), '.') . ' ');
    			} else {
        			$this->_straightOut($value[1] . ' ');
    			}
    			break;
    			
    		case pdf_parser::TYPE_ARRAY:

    			// An array. Output the proper
    			// structure and move on.

    			$this->_straightOut('[');
                for ($i = 0; $i < count($value[1]); $i++) {
    				$this->_writeValue($value[1][$i]);
    			}

    			$this->_out(']');
    			break;

    		case pdf_parser::TYPE_DICTIONARY:

    			// A dictionary.
    			$this->_straightOut('<<');

    			reset ($value[1]);

    			while (list($k, $v) = each($value[1])) {
    				$this->_straightOut($k . ' ');
    				$this->_writeValue($v);
    			}

    			$this->_straightOut('>>');
    			break;

    		case pdf_parser::TYPE_OBJREF:

    			// An indirect object reference
    			// Fill the object stack if needed
    			$cpfn =& $this->currentParser->filename;
    			if (!isset($this->_doneObjStack[$cpfn][$value[1]])) {
    			    $this->_newobj(false, true);
    			    $this->_objStack[$cpfn][$value[1]] = array($this->n, $value);
                    $this->_doneObjStack[$cpfn][$value[1]] = array($this->n, $value);
                }
                $objId = $this->_doneObjStack[$cpfn][$value[1]][0];

    			$this->_out($objId . ' 0 R');
    			break;

    		case pdf_parser::TYPE_STRING:

    			// A string.
                $this->_straightOut('(' . $value[1] . ')');

    			break;

    		case pdf_parser::TYPE_STREAM:

    			// A stream. First, output the
    			// stream dictionary, then the
    			// stream data itself.
                $this->_writeValue($value[1]);
    			$this->_out('stream');
    			$this->_out($value[2][1]);
    			$this->_straightOut("endstream");
    			break;
    			
            case pdf_parser::TYPE_HEX:
                $this->_straightOut('<' . $value[1] . '>');
                break;

            case pdf_parser::TYPE_BOOLEAN:
    		    $this->_straightOut($value[1] ? 'true ' : 'false ');
    		    break;
            
    		case pdf_parser::TYPE_NULL:
                // The null object.

    			$this->_straightOut('null ');
    			break;
    	}
    }
    
    
    /**
     * Modified _out() method so not each call will add a newline to the output.
     */
    protected function _straightOut($s)
    {
        if (!is_subclass_of($this, 'TCPDF')) {
            if ($this->state == 2) {
        		$this->pages[$this->page] .= $s;
            } else {
        		$this->buffer .= $s;
            }

        } else {
            if ($this->state == 2) {
				if ($this->inxobj) {
					// we are inside an XObject template
					$this->xobjects[$this->xobjid]['outdata'] .= $s;
				} else if ((!$this->InFooter) AND isset($this->footerlen[$this->page]) AND ($this->footerlen[$this->page] > 0)) {
					// puts data before page footer
					$pagebuff = $this->getPageBuffer($this->page);
					$page = substr($pagebuff, 0, -$this->footerlen[$this->page]);
					$footer = substr($pagebuff, -$this->footerlen[$this->page]);
					$this->setPageBuffer($this->page, $page . $s . $footer);
					// update footer position
					$this->footerpos[$this->page] += strlen($s);
				} else {
					// set page data
					$this->setPageBuffer($this->page, $s, true);
				}
			} else if ($this->state > 0) {
				// set general data
				$this->setBuffer($s);
			}
        }
    }

    /**
     * Ends the document
     *
     * Overwritten to close opened parsers
     */
    public function _enddoc()
    {
        parent::_enddoc();
        $this->_closeParsers();
    }
    
    /**
     * Close all files opened by parsers.
     *
     * @return boolean
     */
    protected function _closeParsers()
    {
        if ($this->state > 2) {
          	$this->cleanUp();
            return true;
        }

        return false;
    }
    
    /**
     * Removes cycled references and closes the file handles of the parser objects.
     */
    public function cleanUp()
    {
        while (($parser = array_pop($this->parsers)) !== null) {
            /**
             * @var fpdi_pdf_parser $parser
             */
            $parser->closeFile();
        }
    }
}