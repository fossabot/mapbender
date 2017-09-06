<?php
namespace Mapbender\PrintBundle\Component;

use Mapbender\CoreBundle\Component\SecurityContext;

/**
 * Mapbender3 Print Service.
 *
 * @author Stefan Winkelmann
 */
class PrintService extends ImageExportService
{
    /** @var PDF_Extensions */
    protected $pdf;
    protected $conf;
    protected $rotation;
    protected $user;
    protected $tempDir;
    protected $imageWidth;
    protected $imageHeight;

    protected $tempFilePrefix = 'mb_print';

    /** @var  PrintTemplate */
    protected $template;

    /**
     * @var array Default geometry style
     */
    protected $defaultStyle = array(
        "strokeWidth" => 1
    );

    public function doPrint($data)
    {
        $this->setup($data);

        if ($data['rotation'] == 0) {
            $mainMapImage = $this->buildMainMapImage();
        } else {
            $mainMapImage = $this->createFinalRotatedMapImage();
        }

        $mapImagePath = $this->generateTempName('_final');
        imagepng($mainMapImage, $mapImagePath);
        imagedestroy($mainMapImage);

        return $this->buildPdf($mapImagePath);
    }

    private function setup($data)
    {
        $this->user      = $this->getUser();

        // data from client
        $this->data = $data;
        $dpiQuality = $this->getQualityDpi();

        $this->template = new PrintTemplate($this->resourceDir . '/templates', $data['template']);
        // template configuration from odg
        $odgParser = new OdgParser($this->container);
        $this->conf = $conf = $odgParser->getConf($data['template']);

        // image size
        $this->imageWidth = round($conf['map']['width'] / 25.4 * $dpiQuality);
        $this->imageHeight = round($conf['map']['height'] / 25.4 * $dpiQuality);
        $this->mainMapCanvas = $this->setupMainMapCanvas($data);
        $this->mapRequests = $this->setupMapRequests($data);
    }

    protected function setupMapRequests($configuration)
    {
        $formattedRequests = array();

        $center = $this->mainMapCanvas['center'];
        $neededExtent = $this->mainMapCanvas['extent'];
        $neededImageWidth = $this->mainMapCanvas['pixelWidth'];
        $neededImageHeight = $this->mainMapCanvas['pixelHeight'];
        $dpiQuality = $this->getQualityDpi();

        foreach ($configuration['layers'] as $i => $layer) {
            if ($layer['type'] != 'wms') {
                continue;
            }
            $minX = $center['x'] - $neededExtent['width'] * 0.5;
            $minY = $center['y'] - $neededExtent['height'] * 0.5;
            $maxX = $center['x'] + $neededExtent['width'] * 0.5;
            $maxY = $center['y'] + $neededExtent['height'] * 0.5;

            $request = strstr($layer['url'], '&BBOX', true);
            // switch BBOX order for some EPSG if WMS Version 1.3.0
            if (!empty($layer['changeAxis'])){
                $bboxParam = "$minY,$minX,$maxY,$maxX";
            } else {
                $bboxParam = "$minX,$minY,$maxX,$maxY";
            }

            $request .= "&BBOX=$bboxParam";
            $request .= "&WIDTH=${neededImageWidth}&HEIGHT=${neededImageHeight}";

            if (isset($this->data['replace_pattern'])) {
                $request = $this->addReplacePattern($request, $dpiQuality);
            } else {
                if ($dpiQuality != '72') {
                    $request .= '&map_resolution=' . $dpiQuality;
                }
            }

            $formattedRequests[$i] = array(
                'url'     => $request,
                'opacity' => $layer['opacity'],
            );
        }
        return $formattedRequests;
    }

    protected function setupMainMapCanvas($configuration)
    {
        $extent = $configuration['extent'];

        // switch if image is rotated
        $rotation = $configuration['rotation'];
        if ($rotation == 0) {
            $neededImageWidth = $this->imageWidth;
            $neededImageHeight = $this->imageHeight;
            $neededExtent = $extent;
        } else {
            // calculate needed image size
            $neededImageWidth = round(abs(sin(deg2rad($rotation)) * $this->imageHeight) +
                abs(cos(deg2rad($rotation)) * $this->imageWidth));
            $neededImageHeight = round(abs(sin(deg2rad($rotation)) * $this->imageWidth) +
                abs(cos(deg2rad($rotation)) * $this->imageHeight));

            // calculate needed bbox
            $neededExtentWidth = abs(sin(deg2rad($rotation)) * $extent['height']) +
                abs(cos(deg2rad($rotation)) * $extent['width']);
            $neededExtentHeight = abs(sin(deg2rad($rotation)) * $extent['width']) +
                abs(cos(deg2rad($rotation)) * $extent['height']);

            $neededExtent = array(
                'width' => $neededExtentWidth,
                'height' => $neededExtentHeight,
            );
        }

        return array(
            'extent' => $neededExtent,
            'center' => $configuration['center'],
            'pixelWidth' => $neededImageWidth,
            'pixelHeight' => $neededImageHeight,
        );
    }

    /**
     * Modifies request $url with magical undocumented pattern array logic
     *
     * @param string $url
     * @param int $quality
     * @return string
     */
    private function addReplacePattern($url, $quality)
    {
        $default = '';
        foreach ($this->data['replace_pattern'] as $rKey => $pattern) {
            if (isset($pattern['default'])) {
                if(isset($pattern['default'][$quality])){
                    $default = $pattern['default'][$quality];
                }
            } elseif (strpos($url,$pattern['pattern']) !== false) {
                if(isset($pattern['replacement'][$quality])){
                    $url = str_replace($pattern['pattern'], $pattern['replacement'][$quality], $url);
                    $signer = $this->container->get('signer');
                    return $signer->signUrl($url);
                }
            }
        }
        return $url . $default;
    }

    /**
     *
     * @return string absolute path to resulting PNG file
     */
    private function createFinalRotatedMapImage()
    {
        $tempImage = $this->buildMainMapImage();

        // rotate temp image
        $rotation = $this->data['rotation'];
        $imageWidth = $this->imageWidth;
        $imageHeight = $this->imageHeight;
        $transColor = imagecolorallocatealpha($tempImage, 255, 255, 255, 127);
        $rotatedImage = imagerotate($tempImage, $rotation, $transColor);
        imagealphablending($rotatedImage, false);
        imagesavealpha($rotatedImage, true);

        $neededImageWidth = $this->mainMapCanvas['pixelWidth'];
        $neededImageHeight = $this->mainMapCanvas['pixelHeight'];
        // clip final image from rotated
        $rotatedWidth = round(abs(sin(deg2rad($rotation)) * $neededImageHeight) +
            abs(cos(deg2rad($rotation)) * $neededImageWidth));
        $rotatedHeight = round(abs(sin(deg2rad($rotation)) * $neededImageWidth) +
            abs(cos(deg2rad($rotation)) * $neededImageHeight));
        $newx = ($rotatedWidth - $imageWidth ) / 2;
        $newy = ($rotatedHeight - $imageHeight ) / 2;

        $clippedImage = imagecreatetruecolor($imageWidth, $imageHeight);
        imagealphablending($clippedImage, false);
        imagesavealpha($clippedImage, true);
        imagecopy($clippedImage, $rotatedImage, 0, 0, $newx, $newy,
            $imageWidth, $imageHeight);

        return $clippedImage;
    }

    /**
     * @param string $mapImagePath to merged png file
     * @param bool $deleteMapImage to delete file at mapImagePath after loading it (default true)
     * @return string binary PDF output
     */
    private function buildPdf($mapImagePath, $deleteMapImage=true)
    {
        // set format
        if($this->conf['orientation'] == 'portrait'){
            $format = array($this->conf['pageSize']['width'],$this->conf['pageSize']['height']);
            $orientation = 'P';
        }else{
            $format = array($this->conf['pageSize']['height'],$this->conf['pageSize']['width']);
            $orientation = 'L';
        }

        $this->pdf = $pdf = new PDF_Extensions();

        $templatePdf = $this->template->getPdfPath();
        $pageCount = $pdf->setSourceFile($templatePdf);
        $tplidx = $pdf->importPage(1);
        $pdf->SetAutoPageBreak(false);
        $pdf->addPage($orientation, $format);

        $hasTransparentBg = $this->checkPdfBackground($pdf);
        if ($hasTransparentBg == false){
            $pdf->useTemplate($tplidx);
        }

        // add final map image
        $mapUlX = $this->conf['map']['x'];
        $mapUlY = $this->conf['map']['y'];
        $mapWidth = $this->conf['map']['width'];
        $mapHeight = $this->conf['map']['height'];

        $pdf->Image($mapImagePath, $mapUlX, $mapUlY,
                $mapWidth, $mapHeight, 'png', '', false, 0, 5, -1 * 0);
        // add map border (default is black)
        $pdf->Rect($mapUlX, $mapUlY, $mapWidth, $mapHeight);
        if ($deleteMapImage) {
            unlink($mapImagePath);
        }

        if ($hasTransparentBg == true){
            $pdf->useTemplate($tplidx);
        }

        // add northarrow
        if ($northarrow = $this->template->getCustomShape('northarrow')) {
            $this->addNorthArrow($northarrow);
        }

        // get digitizer feature
        if (isset($this->data['digitizer_feature'])) {
            $dfData = $this->data['digitizer_feature'];
            $feature = $this->getFeature($dfData['schemaName'], $dfData['id']);
        }

        // fill text fields
        if (isset($this->conf['fields']) ) {
            foreach ($this->conf['fields'] as $k => $v) {
                list($r, $g, $b) = CSSColorParser::parse($this->conf['fields'][$k]['color']);
                $pdf->SetTextColor($r,$g,$b);
                $pdf->SetFont('Arial', '', $this->conf['fields'][$k]['fontsize']);
                $pdf->SetXY($this->conf['fields'][$k]['x'] - 1,
                    $this->conf['fields'][$k]['y']);

                // continue if extent field is set
                if(preg_match("/^extent/", $k)){
                    continue;
                }

                switch ($k) {
                    case 'date' :
                        $date = new \DateTime;
                        $pdf->Cell($this->conf['fields']['date']['width'],
                            $this->conf['fields']['date']['height'],
                            $date->format('d.m.Y'));
                        break;
                    case 'scale' :
                        $pdf->Cell($this->conf['fields']['scale']['width'],
                            $this->conf['fields']['scale']['height'],
                            '1 : ' . $this->data['scale_select']);
                        break;
                    default:
                        if (isset($this->data['extra'][$k])) {
                            $pdf->MultiCell($this->conf['fields'][$k]['width'],
                                $this->conf['fields'][$k]['height'],
                                utf8_decode($this->data['extra'][$k]));
                        }

                        // fill digitizer feature fields
                        if(preg_match("/^feature./", $k)){
                            if($feature == false){
                                continue;
                            }
                            $attribute = substr(strrchr($k, "."), 1);
                            $pdf->MultiCell($this->conf['fields'][$k]['width'],
                                $this->conf['fields'][$k]['height'],
                                $feature->getAttribute($attribute));
                        }
                        break;
                }
            }
        }

        // reset text color to default black
        $pdf->SetTextColor(0,0,0);

        // add overview map
        if (isset($this->data['overview']) && isset($this->conf['overview']) ) {
            $this->addOverviewMap();
        }

        // add scalebar
        if (isset($this->conf['scalebar']) ) {
            $this->addScaleBar();
        }

        // add coordinates
        if (isset($this->conf['fields']['extent_ur_x']) && isset($this->conf['fields']['extent_ur_y'])
                && isset($this->conf['fields']['extent_ll_x']) && isset($this->conf['fields']['extent_ll_y']))
        {
            $this->addCoordinates();
        }

        // add dynamic logo
        if (isset($this->conf['dynamic_image']) && $this->conf['dynamic_image']){
            $this->addDynamicImage();
        }

        // add dynamic text
        if (isset($this->conf['fields'])
            && isset($this->conf['fields']['dynamic_text'])
            && $this->conf['fields']['dynamic_text']){
            $this->addDynamicText();
        }

        // add legend
        if (isset($this->data['legends']) && !empty($this->data['legends'])){
            $this->addLegend();
        }

        return $pdf->Output(null, 'S');
    }

    private function addNorthArrow($config)
    {
        $pngPath = $this->resourceDir . '/images/northarrow.png';
        $rotation = $this->data['rotation'];
        $rotatedImageName = null;

        if($rotation != 0){
            $image = imagecreatefrompng($pngPath);
            $transColor = imagecolorallocatealpha($image, 255, 255, 255, 0);
            $rotatedImage = imagerotate($image, $rotation, $transColor);
            $rotatedImageName = $this->generateTempName('_northarrow');

            $srcSize = array(imagesx($rotatedImage), imagesy($rotatedImage));
            $destSize = getimagesize($pngPath);
            $x = ($srcSize[0] - $destSize[0]) / 2;
            $y = ($srcSize[1] - $destSize[1]) / 2;
            $destImage = imagecreatetruecolor($destSize[0], $destSize[1]);
            imagecopy($destImage, $rotatedImage, 0, 0, $x, $y, $srcSize[0], $srcSize[1]);
            imagepng($destImage, $rotatedImageName);
            $pngPath = $rotatedImageName;
            imagedestroy($image);
            imagedestroy($rotatedImage);
            imagedestroy($destImage);
        }

        $this->pdf->Image($pngPath,
                            $config['x'],
                            $config['y'],
                            $config['width'],
                            $config['height'],
                            'png');
        if($rotatedImageName){
            unlink($rotatedImageName);
        }
    }

    private function addOverviewMap()
    {
        // calculate needed image size
        $quality = $this->data['quality'];
        $ovImageWidth = round($this->conf['overview']['width'] / 25.4 * $quality);
        $ovImageHeight = round($this->conf['overview']['height'] / 25.4 * $quality);

        $changeAxis = false;

        // get images
        $tempNames = array();
        $logger = $this->container->get("logger");
        foreach ($this->data['overview'] as $i => $layer) {
            // calculate needed bbox
            $ovWidth = $this->conf['overview']['width'] * $layer['scale'] / 1000;
            $ovHeight = $this->conf['overview']['height'] * $layer['scale'] / 1000;
            $centerx = $this->data['center']['x'];
            $centery = $this->data['center']['y'];

            $minX = $centerx - $ovWidth * 0.5;
            $minY = $centery - $ovHeight * 0.5;
            $maxX = $centerx + $ovWidth * 0.5;
            $maxY = $centery + $ovHeight * 0.5;
            if (empty($layer['changeAxis'])) {
                $bboxParam = "&BBOX={$minX},{$minY},{$maxX},{$maxY}";
            } else {
                $bboxParam = "&BBOX={$minY},{$minX},{$maxY},{$maxX}";
                $changeAxis = true;
            }

            $url = strstr($layer['url'], '&BBOX', true);
            $url .= $bboxParam;

            $logger->debug("Print Overview Request Nr.: " . $i . ' ' . $url);
            try {
                $im = $this->loadMapTile($url, $ovImageWidth, $ovImageHeight);
                $imageName = $this->generateTempName();
                imagepng($im, $imageName);
                $tempNames[] = $imageName;
                imagedestroy($im);
            } catch (\Exception $e) {
                // ignore missing overview layer
            }
        }

        // create final merged image
        $finalImageName = $this->generateTempName('_merged');
        $image = $this->mergeImages($tempNames, $ovImageWidth, $ovImageHeight);

        // add red extent rectangle
        if (!$changeAxis) {
            $ll_x = $this->data['extent_feature'][3]['x'];
            $ll_y = $this->data['extent_feature'][3]['y'];
            $ul_x = $this->data['extent_feature'][0]['x'];
            $ul_y = $this->data['extent_feature'][0]['y'];

            $lr_x = $this->data['extent_feature'][2]['x'];
            $lr_y = $this->data['extent_feature'][2]['y'];
            $ur_x = $this->data['extent_feature'][1]['x'];
            $ur_y = $this->data['extent_feature'][1]['y'];

            $p1 = $this->realWorld2ovMapPos($ovWidth, $ovHeight, $ll_x, $ll_y);
            $p2 = $this->realWorld2ovMapPos($ovWidth, $ovHeight, $ul_x, $ul_y);
            $p3 = $this->realWorld2ovMapPos($ovWidth, $ovHeight, $ur_x, $ur_y);
            $p4 = $this->realWorld2ovMapPos($ovWidth, $ovHeight, $lr_x, $lr_y);
        } else {
            $ul_x = $this->data['extent_feature'][3]['x'];
            $ul_y = $this->data['extent_feature'][3]['y'];
            $ll_x = $this->data['extent_feature'][0]['x'];
            $ll_y = $this->data['extent_feature'][0]['y'];

            $ur_x = $this->data['extent_feature'][2]['x'];
            $ur_y = $this->data['extent_feature'][2]['y'];
            $lr_x = $this->data['extent_feature'][1]['x'];
            $lr_y = $this->data['extent_feature'][1]['y'];

            $p1 = $this->realWorld2ovMapPos($ovHeight, $ovWidth, $ll_x, $ll_y);
            $p2 = $this->realWorld2ovMapPos($ovHeight, $ovWidth, $ul_x, $ul_y);
            $p3 = $this->realWorld2ovMapPos($ovHeight, $ovWidth, $ur_x, $ur_y);
            $p4 = $this->realWorld2ovMapPos($ovHeight, $ovWidth, $lr_x, $lr_y);
        }

        $red = ImageColorAllocate($image,255,0,0);
        imageline ( $image, $p1[0], $p1[1], $p2[0], $p2[1], $red);
        imageline ( $image, $p2[0], $p2[1], $p3[0], $p3[1], $red);
        imageline ( $image, $p3[0], $p3[1], $p4[0], $p4[1], $red);
        imageline ( $image, $p4[0], $p4[1], $p1[0], $p1[1], $red);

        imagepng($image, $finalImageName);
        imagedestroy($image);

        // add image to pdf
        $this->pdf->Image($finalImageName,
                    $this->conf['overview']['x'],
                    $this->conf['overview']['y'],
                    $this->conf['overview']['width'],
                    $this->conf['overview']['height'],
                    'png');
        // draw border rectangle
        $this->pdf->Rect($this->conf['overview']['x'],
                         $this->conf['overview']['y'],
                         $this->conf['overview']['width'],
                         $this->conf['overview']['height']);

        unlink($finalImageName);
    }

    private function addScaleBar()
    {
        $pdf = $this->pdf;
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetFillColor(0,0,0);
        $pdf->SetFont('arial', '', 10 );

        $length = 0.01 * $this->data['scale_select'] * 5;
        $suffix = 'm';

        $pdf->Text( $this->conf['scalebar']['x'] -1 , $this->conf['scalebar']['y'] - 1 , '0' );
        $pdf->Text( $this->conf['scalebar']['x'] + 46, $this->conf['scalebar']['y'] - 1 , $length . '' . $suffix);

        $pdf->Rect($this->conf['scalebar']['x'], $this->conf['scalebar']['y'], 10, 2, 'FD');
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($this->conf['scalebar']['x'] + 10 , $this->conf['scalebar']['y'], 10, 2, 'FD');
        $pdf->SetFillColor(0,0,0);
        $pdf->Rect($this->conf['scalebar']['x'] + 20  , $this->conf['scalebar']['y'], 10, 2, 'FD');
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($this->conf['scalebar']['x'] + 30 , $this->conf['scalebar']['y'], 10, 2, 'FD');
        $pdf->SetFillColor(0,0,0);
        $pdf->Rect($this->conf['scalebar']['x'] + 40  , $this->conf['scalebar']['y'], 10, 2, 'FD');
    }

    private function addCoordinates()
    {
        $pdf = $this->pdf;

        $corrFactor = 2;
        $precision = 2;
        // correction factor and round precision if WGS84
        if($this->data['extent']['width'] < 1){
             $corrFactor = 3;
             $precision = 6;
        }

        // upper right Y
        $pdf->SetFont('Arial', '', $this->conf['fields']['extent_ur_y']['fontsize']);
        $pdf->Text($this->conf['fields']['extent_ur_y']['x'] + $corrFactor,
                    $this->conf['fields']['extent_ur_y']['y'] + 3,
                    round($this->data['extent_feature'][2]['y'], $precision));

        // upper right X
        $pdf->SetFont('Arial', '', $this->conf['fields']['extent_ur_x']['fontsize']);
        $pdf->TextWithDirection($this->conf['fields']['extent_ur_x']['x'] + 1,
                    $this->conf['fields']['extent_ur_x']['y'],
                    round($this->data['extent_feature'][2]['x'], $precision),'D');

        // lower left Y
        $pdf->SetFont('Arial', '', $this->conf['fields']['extent_ll_y']['fontsize']);
        $pdf->Text($this->conf['fields']['extent_ll_y']['x'],
                    $this->conf['fields']['extent_ll_y']['y'] + 3,
                    round($this->data['extent_feature'][0]['y'], $precision));

        // lower left X
        $pdf->SetFont('Arial', '', $this->conf['fields']['extent_ll_x']['fontsize']);
        $pdf->TextWithDirection($this->conf['fields']['extent_ll_x']['x'] + 3,
                    $this->conf['fields']['extent_ll_x']['y'] + 30,
                    round($this->data['extent_feature'][0]['x'], $precision),'U');
    }

    private function addDynamicImage()
    {
        if (!$this->user || $this->user == 'anon.') {
            return;
        }

        $groups = $this->user->getGroups();
        $group = $groups[0];

        if(!isset($group)){
            return;
        }

        $dynImage = $this->resourceDir . '/images/' . $group->getTitle() . '.png';
        if(file_exists ($dynImage)){
            $this->pdf->Image($dynImage,
                            $this->conf['dynamic_image']['x'],
                            $this->conf['dynamic_image']['y'],
                            0,
                            $this->conf['dynamic_image']['height'],
                            'png');
            return;
        }

    }

    private function addDynamicText()
    {
        if (!$this->user || $this->user == 'anon.'){
            return;
        }

        $groups = $this->user->getGroups();
        $group = $groups[0];

        if(!isset($group)){
            return;
        }

        $this->pdf->SetFont('Arial', '', $this->conf['fields']['dynamic_text']['fontsize']);
        $this->pdf->MultiCell($this->conf['fields']['dynamic_text']['width'],
                $this->conf['fields']['dynamic_text']['height'],
                utf8_decode($group->getDescription()));

    }

    private function getFeature($schemaName, $featureId)
    {
        $featureTypeService = $this->container->get('features');
        $featureType = $featureTypeService->get($schemaName);
        $feature = $featureType->get($featureId);
        return $feature;
    }




    protected function drawPoint($geometry, $image, $dummy=false)
    {
        return parent::drawPoint($geometry, $image, false);
    }

    /**
     * @return array[]
     */
    protected function getGeometryLayers()
    {
        $geoLayers = array();
        foreach ($this->data['layers'] as $layer) {
            if ('GeoJSON+Style' === $layer['type']) {
                $geoLayers[] = $layer;
            }
        }
        return $geoLayers;
    }


    private function addLegend()
    {
        $titleHeight = 5;

        if (!empty($this->conf['legend'])) {
          // start printing legend in a configured box on first page
          $availableHeight = $this->conf['legend']['height'];
          $availableWidth = $this->conf['legend']['width'];
          $xStartPosition = $this->conf['legend']['x'] + 5;
          $yStartPosition = $this->conf['legend']['y'] + 5;
        } else {
          // start printing legend on dedicated page
          $this->newLegendPage();
          $xStartPosition = 5;
          $yStartPosition = 10;
          $availableHeight = $this->pdf->getHeight();
          $availableWidth = $this->pdf->getWidth();
        }

        $x = 0;
        $y = 0;
        $c = 1;
        foreach ($this->data['legends'] as $idx => $legendArray) {
            foreach ($legendArray as $title => $legendUrl) {

                if (preg_match('/request=GetLegendGraphic/i', urldecode($legendUrl)) === 0) {
                    continue;
                }
                $image = $this->generateTempName('_legend');

                try {
                    $this->storeImage($image, $legendUrl);
                } catch (\Exception $e) {
                    // ignore the missing legend image, continue without it
                    continue;
                }
                $needNewPage = false;

                $size  = getimagesize($image);
                $heightNeeded = round($size[1] * 25.4 / 96) + 5 + $titleHeight;

                if ($c > 1) {
                    if ($y + $heightNeeded > $availableHeight) {
                        $nextColumnX = $x + 105;
                        if ($nextColumnX + 20 <= $availableWidth) {
                            $x = $nextColumnX;
                            $y = 0;
                        } else {
                            $needNewPage = true;
                        }
                    }
                }

                if ($needNewPage) {
                    // no space on current page (or embedded legend box) for next legend item
                    // => spill to new page, switch to full-page mode
                    $this->newLegendPage();
                    $xStartPosition = 5;
                    $yStartPosition = 10;
                    $x = 0;
                    $y = 0;
                    $availableWidth = $this->pdf->getWidth();
                    $availableHeight = $this->pdf->getHeight();
                }

                $this->pdf->setXY($x + $xStartPosition, $y + $yStartPosition);
                $this->pdf->Cell(0,0, utf8_decode($title));
                $this->pdf->Image($image,
                    $x + $xStartPosition,
                    $y + $titleHeight + $yStartPosition,
                    ($size[0] * 25.4 / 96), ($size[1] * 25.4 / 96), 'png', '', false, 0);

                $y += $heightNeeded;

                unlink($image);
                $c++;
            }
        }
    }


    /**
     * Append a new page to $this->pdf for a legend spilling over. The page is always in portrait mode. This also
     * switches the font.
     * If configured, a watermark image may be inserted on the new page.
     *
     */
    protected function newLegendPage()
    {
        $this->pdf->addPage('P');
        $this->pdf->SetFont('Arial', 'B', 11);
        if (!empty($this->conf['legendpage_image'])) {
            $this->addLegendPageImage();
        }
    }

    private function addLegendPageImage()
    {

        $legendpageImage = $this->resourceDir . '/images/' . 'legendpage_image'. '.png';

        if (!$this->user || $this->user == 'anon.') {
            $legendpageImage = $this->resourceDir . '/images/' . 'legendpage_image'. '.png';
        }else{
          $groups = $this->user->getGroups();
          $group = $groups[0];

          if(isset($group)){
              $legendpageImage = $this->resourceDir . '/images/' . $group->getTitle() . '.png';
          }
        }

        if(file_exists ($legendpageImage)){
            $this->pdf->Image($legendpageImage,
                            $this->conf['legendpage_image']['x'],
                            $this->conf['legendpage_image']['y'],
                            0,
                            $this->conf['legendpage_image']['height'],
                            'png');
        }
    }

    private function realWorld2ovMapPos($ovWidth, $ovHeight, $rw_x, $rw_y)
    {
        $quality  = $this->getQualityDpi();
        $centerx  = $this->data['center']['x'];
        $centery  = $this->data['center']['y'];
        $minX     = $centerx - $ovWidth * 0.5;
        $minY     = $centery - $ovHeight * 0.5;
        $maxX     = $centerx + $ovWidth * 0.5;
        $maxY     = $centery + $ovHeight * 0.5;
        $extentx  = $maxX - $minX;
        $extenty  = $maxY - $minY;
        $pixPos_x = (($rw_x - $minX) / $extentx) * round($this->conf['overview']['width'] / 25.4 * $quality);
        $pixPos_y = (($maxY - $rw_y) / $extenty) * round($this->conf['overview']['height'] / 25.4 * $quality);

        return array($pixPos_x, $pixPos_y);
    }

    /**
     * @inheritdoc
     */
    protected function getStyle($geometry)
    {
        return array_merge($this->defaultStyle, parent::getStyle($geometry));
    }

    private function checkPdfBackground($pdf) {
        $pdfArray = (array) $pdf;
        $pdfFile = $pdfArray['currentFilename'];
        $pdfSubArray = (array) $pdfArray['parsers'][$pdfFile];
        $prefix = chr(0) . '*' . chr(0);
        $pdfSubArray2 = $pdfSubArray[$prefix . '_root'][1][1];

        if (sizeof($pdfSubArray2) > 0 && !array_key_exists('/Outlines', $pdfSubArray2)) {
            return true;
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    protected function getQualityDpi()
    {
        if (!empty($this->data['quality'])) {
            return intval($this->data['quality']);
        } else {
            return parent::getQualityDpi();
        }
    }

    /**
     * @return mixed|null
     */
    protected function getUser()
    {
        /** @var SecurityContext $securityContext */
        $securityContext = $this->container->get('security.context');
        $token           = $securityContext->getToken();
        if ($token) {
            return $token->getUser();
        } else {
            return null;
        }
    }
}
