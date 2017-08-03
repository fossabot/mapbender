<?php
namespace Mapbender\PrintBundle\Component;

use Mapbender\CoreBundle\Component\SecurityContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use OwsProxy3\CoreBundle\Component\ProxyQuery;
use OwsProxy3\CoreBundle\Component\CommonProxy;

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
    protected $neededExtentWidth;
    protected $neededExtentHeight;
    protected $neededImageWidth;
    protected $neededImageHeight;

    protected $tempFilePrefix = 'mb_print';

    /**
     * @var array Default geometry style
     */
    protected $defaultStyle = array(
        "strokeWidth" => 1
    );

    public function doPrint($data)
    {
        $this->reset();
        $this->setup($data);

        if ($data['rotation'] == 0) {
            $mapImagePath = $this->createFinalMapImage();
        } else {
            $mapImagePath = $this->createFinalRotatedMapImage();
        }

        return $this->buildPdf($mapImagePath);
    }

    private function setup($data)
    {
        $this->user      = $this->getUser();

        // data from client
        $this->data = $data;
        $dpiQuality = $this->getQualityDpi();

        // template configuration from odg
        $odgParser = new OdgParser($this->container);
        $this->conf = $conf = $odgParser->getConf($data['template']);

        // image size
        $this->imageWidth = round($conf['map']['width'] / 25.4 * $dpiQuality);
        $this->imageHeight = round($conf['map']['height'] / 25.4 * $dpiQuality);

        foreach ($data['layers'] as $i => $layer) {
            if ($layer['type'] != 'wms') {
                continue;
            }
            $request = strstr($layer['url'], '&BBOX', true);

            $extentWidth = $data['extent']['width'];
            $extentHeight = $data['extent']['height'];
            $centerx = $data['center']['x'];
            $centery = $data['center']['y'];

            // switch axis for some EPSG if WMS Version 1.3.0
            if (!empty($layer['changeAxis'])){
                $extentWidth = $data['extent']['height'];
                $extentHeight = $data['extent']['width'];
                $centerx = $data['center']['y'];
                $centery = $data['center']['x'];
            }

            // switch if image is rotated
            $this->rotation = $rotation = $data['rotation'];
            if ($rotation == 0) {
                // calculate needed bbox
                $minX = $centerx - $extentWidth * 0.5;
                $minY = $centery - $extentHeight * 0.5;
                $maxX = $centerx + $extentWidth * 0.5;
                $maxY = $centery + $extentHeight * 0.5;

                $width = '&WIDTH=' . $this->imageWidth;
                $height =  '&HEIGHT=' . $this->imageHeight;
            }else{
                // calculate needed bbox
                $neededExtentWidth = abs(sin(deg2rad($rotation)) * $extentHeight) +
                    abs(cos(deg2rad($rotation)) * $extentWidth);
                $neededExtentHeight = abs(sin(deg2rad($rotation)) * $extentWidth) +
                    abs(cos(deg2rad($rotation)) * $extentHeight);

                $this->neededExtentWidth = $neededExtentWidth;
                $this->neededExtentHeight = $neededExtentHeight;

                $minX = $centerx - $neededExtentWidth * 0.5;
                $minY = $centery - $neededExtentHeight * 0.5;
                $maxX = $centerx + $neededExtentWidth * 0.5;
                $maxY = $centery + $neededExtentHeight * 0.5;

                // calculate needed image size
                $neededImageWidth = round(abs(sin(deg2rad($rotation)) * $this->imageHeight) +
                    abs(cos(deg2rad($rotation)) * $this->imageWidth));
                $neededImageHeight = round(abs(sin(deg2rad($rotation)) * $this->imageWidth) +
                    abs(cos(deg2rad($rotation)) * $this->imageHeight));

                $this->neededImageWidth = $neededImageWidth;
                $this->neededImageHeight = $neededImageHeight;

                $width = '&WIDTH=' . $neededImageWidth;
                $height =  '&HEIGHT=' . $neededImageHeight;
            }

            $request .= '&BBOX=' . $minX . ',' . $minY . ',' . $maxX . ',' . $maxY;
            $request .= $width . $height;

            if (isset($this->data['replace_pattern'])) {
                $request = $this->addReplacePattern($request, $dpiQuality);
            } else {
                if ($dpiQuality != '72') {
                    $request .= '&map_resolution=' . $dpiQuality;
                }
            }

            $this->mapRequests[$i] = array(
                'url'     => $request,
                'opacity' => $layer['opacity'],
            );
        }
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
    private function createFinalMapImage()
    {
        $width = $this->imageWidth;
        $height = $this->imageHeight;
        $imageResource = $this->getImages($this->mapRequests, $width, $height);

        //draw features
        $this->drawFeatures($imageResource);
        $imagePath = $this->generateTempName('_final');
        imagepng($imageResource, $imagePath);
        return $imagePath;
    }

    /**
     *
     * @return string absolute path to resulting PNG file
     */
    private function createFinalRotatedMapImage()
    {
        $rotation = $this->rotation;
        $neededImageWidth = $this->neededImageWidth;
        $neededImageHeight = $this->neededImageHeight;
        $imageWidth = $this->imageWidth;
        $imageHeight = $this->imageHeight;

        // create temp unrotated merged image
        $tempImage = $this->getImages($this->mapRequests, $neededImageWidth, $neededImageHeight);
        $this->drawFeatures($tempImage);

        // rotate temp image
        $transColor = imagecolorallocatealpha($tempImage, 255, 255, 255, 127);
        $rotatedImage = imagerotate($tempImage, $rotation, $transColor);
        imagealphablending($rotatedImage, false);
        imagesavealpha($rotatedImage, true);
        $rotatedImageName = $this->generateTempName('_rotated');
        imagepng($rotatedImage, $rotatedImageName);
        unlink($rotatedImageName);

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

        $resultPath = $this->generateTempName('_final');
        imagepng($clippedImage, $resultPath);
        return $resultPath;
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

        $template = $this->data['template'];
        $pdfFile = $this->resourceDir . '/templates/' . $template . '.pdf';
        $pageCount = $pdf->setSourceFile($pdfFile);
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
        if (isset($this->conf['northarrow'])) {
            $this->addNorthArrow();
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

    private function addNorthArrow()
    {
        $northarrow = $this->resourceDir . '/images/northarrow.png';
        $rotation = $this->rotation;
        $rotatedImageName = null;

        if($rotation != 0){
            $image = imagecreatefrompng($northarrow);
            $transColor = imagecolorallocatealpha($image, 255, 255, 255, 0);
            $rotatedImage = imagerotate($image, $rotation, $transColor);
            $rotatedImageName = $this->generateTempName('_northarrow');
            imagepng($rotatedImage, $rotatedImageName);

            if ($rotation == 90 || $rotation == 270) {
                //
            } else {
                $srcImage = imagecreatefrompng($rotatedImageName);
                $srcSize = getimagesize($rotatedImageName);
                $destSize = getimagesize($northarrow);
                $x = ($srcSize[0] - $destSize[0]) / 2;
                $y = ($srcSize[1] - $destSize[1]) / 2;
                $destImage = imagecreatetruecolor($destSize[0], $destSize[1]);
                imagecopy($destImage, $srcImage, 0, 0, $x, $y, $srcSize[0], $srcSize[1]);
                imagepng($destImage, $rotatedImageName);
            }
            $northarrow = $rotatedImageName;
        }

        $this->pdf->Image($northarrow,
                            $this->conf['northarrow']['x'],
                            $this->conf['northarrow']['y'],
                            $this->conf['northarrow']['width'],
                            $this->conf['northarrow']['height'],
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
                imagesavealpha($im, true);
                imagepng($im, $imageName);
                $tempNames[] = $imageName;
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

    private function drawPolygon($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        foreach($geometry['coordinates'] as $ring) {
            if(count($ring) < 3) {
                continue;
            }

            $points = array();
            foreach($ring as $c) {
                if($this->rotation == 0){
                    $p = $this->realWorld2mapPos($c[0], $c[1]);
                }else{
                    $p = $this->realWorld2rotatedMapPos($c[0], $c[1]);
                }
                $points[] = floatval($p[0]);
                $points[] = floatval($p[1]);
            }
            imagesetthickness($image, 0);

            if($style['fillOpacity'] > 0){
                $color = $this->getColor(
                    $style['fillColor'],
                    $style['fillOpacity'],
                    $image);
                imagefilledpolygon($image, $points, count($ring), $color);
            }
            // Border
            if ($style['strokeWidth'] > 0) {
                $color = $this->getColor(
                    $style['strokeColor'],
                    $style['strokeOpacity'],
                    $image);
                imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
                imagepolygon($image, $points, count($ring), $color);
            }
        }
    }

    private function drawMultiPolygon($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        foreach($geometry['coordinates'] as $element) {
            foreach($element as $ring) {
                if(count($ring) < 3) {
                    continue;
                }

                $points = array();
                foreach($ring as $c) {
                    if($this->rotation == 0){
                        $p = $this->realWorld2mapPos($c[0], $c[1]);
                    }else{
                        $p = $this->realWorld2rotatedMapPos($c[0], $c[1]);
                    }
                    $points[] = floatval($p[0]);
                    $points[] = floatval($p[1]);
                }
                imagesetthickness($image, 0);
                // Filled area
                if($style['fillOpacity'] > 0){
                    $color = $this->getColor(
                        $style['fillColor'],
                        $style['fillOpacity'],
                        $image);
                    imagefilledpolygon($image, $points, count($ring), $color);
                }
                // Border
                if ($style['strokeWidth'] > 0) {
                    $color = $this->getColor(
                        $style['strokeColor'],
                        $style['strokeOpacity'],
                        $image);
                    imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
                    imagepolygon($image, $points, count($ring), $color);
                }
            }
        }
    }

    private function drawLineString($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
        if ($style['strokeWidth'] == 0) {
            return;
        }
        imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);

        for($i = 1; $i < count($geometry['coordinates']); $i++) {

            if($this->rotation == 0){
                $from = $this->realWorld2mapPos(
                    $geometry['coordinates'][$i - 1][0],
                    $geometry['coordinates'][$i - 1][1]);
                $to = $this->realWorld2mapPos(
                    $geometry['coordinates'][$i][0],
                    $geometry['coordinates'][$i][1]);
            }else{
                $from = $this->realWorld2rotatedMapPos(
                    $geometry['coordinates'][$i - 1][0],
                    $geometry['coordinates'][$i - 1][1]);
                $to = $this->realWorld2rotatedMapPos(
                    $geometry['coordinates'][$i][0],
                    $geometry['coordinates'][$i][1]);
            }

            imageline($image, $from[0], $from[1], $to[0], $to[1], $color);
        }
    }

    private function drawMultiLineString($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
        if ($style['strokeWidth'] == 0) {
            return;
        }
        imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);

        foreach($geometry['coordinates'] as $coords) {
            for($i = 1; $i < count($coords); $i++) {
                if($this->rotation == 0){
                        $from = $this->realWorld2mapPos(
                                $coords[$i - 1][0],
                                $coords[$i - 1][1]);
                        $to = $this->realWorld2mapPos(
                                $coords[$i][0],
                                $coords[$i][1]);
                }else{
                        $from = $this->realWorld2rotatedMapPos(
                                $coords[$i - 1][0],
                                $coords[$i - 1][1]);
                        $to = $this->realWorld2rotatedMapPos(
                                $coords[$i][0],
                                $coords[$i][1]);
                }
                imageline($image, $from[0], $from[1], $to[0], $to[1], $color);
            }
        }
    }

    private function drawPoint($geometry, $image)
    {
        $style = $this->getStyle($geometry);
        $c = $geometry['coordinates'];
        $resizeFactor = $this->getResizeFactor();

        if($this->rotation == 0){
            $p = $this->realWorld2mapPos($c[0], $c[1]);
        }else{
            $p = $this->realWorld2rotatedMapPos($c[0], $c[1]);
        }

        if(isset($style['label'])){
            $this->drawHaloText($image, $p, 10, $style['label'], $style);
        }

        $radius = $resizeFactor * $style['pointRadius'];
        // Filled circle
        if($style['fillOpacity'] > 0){
            $color = $this->getColor(
                $style['fillColor'],
                $style['fillOpacity'],
                $image);
            imagesetthickness($image, 0);
            imagefilledellipse($image, $p[0], $p[1], 2 * $radius, 2 * $radius, $color);
        }
        // Circle border
        if ($style['strokeWidth'] > 0 && $style['strokeOpacity'] > 0) {
            $color = $this->getColor(
                $style['strokeColor'],
                $style['strokeOpacity'],
                $image);
            imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
            imageellipse($image, $p[0], $p[1], 2 * $radius, 2 * $radius, $color);
        }
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

    /**
     * @param resource $targetImage GD image to draw on
     */
    private function drawFeatures($targetImage)
    {
        imagesavealpha($targetImage, true);
        imagealphablending($targetImage, true);

        foreach ($this->getGeometryLayers() as $layer) {
            foreach ($layer['geometries'] as $geometry) {
                $renderMethodName = 'draw' . $geometry['type'];
                if (!method_exists($this, $renderMethodName)) {
                    continue;
                    //throw new \RuntimeException('Can not draw geometries of type "' . $geometry['type'] . '".');
                }
                $this->$renderMethodName($geometry, $targetImage);
            }
        }
    }

    private function addLegend()
    {
        if(isset($this->conf['legend']) && $this->conf['legend']){
          // print legend on first
          $height = $this->conf['legend']['height'];
          $width = $this->conf['legend']['width'];
          $xStartPosition = $this->conf['legend']['x'];
          $yStartPosition = $this->conf['legend']['y'];
          $x = $xStartPosition + 5;
          $y = $yStartPosition + 5;
          $legendConf = true;
        }else{
          // print legend on second page
          $this->pdf->addPage('P');
          $this->pdf->SetFont('Arial', 'B', 11);
          $x = 5;
          $y = 10;
          $height = $this->pdf->getHeight();
          $width = $this->pdf->getWidth();
          $legendConf = false;
          if(isset($this->conf['legendpage_image']) && $this->conf['legendpage_image']){
             $this->addLegendPageImage();
          }
          $xStartPosition = 0;
          $yStartPosition = 0;
        }

        foreach ($this->data['legends'] as $idx => $legendArray) {
            $c         = 1;
            $arraySize = count($legendArray);
            foreach ($legendArray as $title => $legendUrl) {

                if (preg_match('/request=GetLegendGraphic/i', urldecode($legendUrl)) === 0) {
                    continue;
                }

                try {
                    $image = $this->downloadLegendImage($legendUrl);
                } catch (\Exception $e) {
                    // ignore the missing legend image, continue without it
                    continue;
                }

                $size  = getimagesize($image);
                $tempY = round($size[1] * 25.4 / 96) + 10;

                if ($c > 1) {
                    // print legend on second page
                    if($y + $tempY + 10 > ($this->pdf->getHeight()) && $legendConf == false){
                        $x += 105;
                        $y = 10;
                        if(isset($this->conf['legendpage_image']) && $this->conf['legendpage_image']){
                           $this->addLegendPageImage();
                        }
                        if($x + 20 > ($this->pdf->getWidth())){
                            $this->pdf->addPage('P');
                            $x = 5;
                            $y = 10;
                            if(isset($this->conf['legendpage_image']) && $this->conf['legendpage_image']){
                               $this->addLegendPageImage();
                            }
                        }
                    }


                    // print legend on first page
                    if(($y-$yStartPosition) + $tempY + 10 > $height && $width > 100 && $legendConf == true){
                        $x += $x + 105;
                        $y = $yStartPosition + 5;
                        if($x - $xStartPosition + 20 > $width){
                            $this->pdf->addPage('P');
                            $x = 5;
                            $y = 10;
                            $legendConf = false;
                            if (!empty($this->conf['legendpage_image'])) {
                               $this->addLegendPageImage();
                            }
                        }
                    }else if (($y-$yStartPosition) + $tempY + 10 > $height && $legendConf == true){
                            $this->pdf->addPage('P');
                            $x = 5;
                            $y = 10;
                            $legendConf = false;
                            if (!empty($this->conf['legendpage_image'])) {
                               $this->addLegendPageImage();
                            }
                    }
                }


                if ($legendConf == true) {
                    // add legend in legend region on first page
                    // To Be doneCell(0,0,  utf8_decode($title));
                    $this->pdf->setXY($x,$y);
                    $this->pdf->Cell(0,0,  utf8_decode($title));
                    $this->pdf->Image($image,
                                $x,
                                $y +5 ,
                                ($size[0] * 25.4 / 96), ($size[1] * 25.4 / 96), 'png', '', false, 0);

                        $y += round($size[1] * 25.4 / 96) + 10;
                        if(($y - $yStartPosition + 10 ) > $height && $width > 100){
                            $x +=  105;
                            $y = $yStartPosition + 10;
                        }
                        if(($x - $xStartPosition + 10) > $width && $c < $arraySize ){
                            $this->pdf->addPage('P');
                            $x = 5;
                            $y = 10;
                            $this->pdf->SetFont('Arial', 'B', 11);
                            $height = $this->pdf->getHeight();
                            $width = $this->pdf->getWidth();
                            $legendConf = false;
                            if (!empty($this->conf['legendpage_image'])) {
                               $this->addLegendPageImage();
                            }
                        }

                  }else{
                      // print legend on second page
                      $this->pdf->setXY($x,$y);
                      $this->pdf->Cell(0,0,  utf8_decode($title));
                      $this->pdf->Image($image, $x, $y + 5, ($size[0] * 25.4 / 96), ($size[1] * 25.4 / 96), 'png', '', false, 0);

                      $y += round($size[1] * 25.4 / 96) + 10;
                      if($y > ($this->pdf->getHeight())){
                          $x += 105;
                          $y = 10;
                      }
                      if($x + 20 > ($this->pdf->getWidth()) && $c < $arraySize){
                          $this->pdf->addPage('P');
                          $x = 5;
                          $y = 10;
                            if (!empty($this->conf['legendpage_image'])) {
                               $this->addLegendPageImage();
                            }
                      }

                  }

                unlink($image);
                $c++;
            }
        }
    }


    /**
     * @param string $url
     * @return string path to created local copy
     */
    private function downloadLegendImage($url)
    {
        $response = $this->mapRequest($url);
        $imageResource = $this->serviceResponseToGdImage($response);
        $imageName  = $this->generateTempName('_legend');
        imagepng($imageResource, $imageName);
        imagedestroy($imageResource);
        return $imageName;
    }

    /**
     * @param float $rw_x
     * @param float $rw_y
     * @return float[] pixel offset x / y
     * @todo: Consolidate with both parent implementation and "realWorld2rotatedMapPos".
     *        The incompatibility here is the only reason we have to keep the (identical)
     *        copy-pasted copies of private drawFeatures all other draw* methods it calls.
     */
    private function realWorld2mapPos($rw_x, $rw_y)
    {
        $quality   = $this->getQualityDpi();
        $mapWidth  = $this->data['extent']['width'];
        $mapHeight = $this->data['extent']['height'];
        $centerx   = $this->data['center']['x'];
        $centery   = $this->data['center']['y'];
        $minX      = $centerx - $mapWidth * 0.5;
        $minY      = $centery - $mapHeight * 0.5;
        $maxX      = $centerx + $mapWidth * 0.5;
        $maxY      = $centery + $mapHeight * 0.5;
        $extentx   = $maxX - $minX;
        $extenty   = $maxY - $minY;
        $pixPos_x  = (($rw_x - $minX) / $extentx) * round($this->conf['map']['width'] / 25.4 * $quality);
        $pixPos_y  = (($maxY - $rw_y) / $extenty) * round($this->conf['map']['height'] / 25.4 * $quality);

        return array($pixPos_x, $pixPos_y);
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

    private function realWorld2rotatedMapPos($rw_x, $rw_y)
    {
        $centerx  = $this->data['center']['x'];
        $centery  = $this->data['center']['y'];
        $minX     = $centerx - $this->neededExtentWidth * 0.5;
        $minY     = $centery - $this->neededExtentHeight * 0.5;
        $maxX     = $centerx + $this->neededExtentWidth * 0.5;
        $maxY     = $centery + $this->neededExtentHeight * 0.5;
        $extentx  = $maxX - $minX;
        $extenty  = $maxY - $minY;
        $pixPos_x = (($rw_x - $minX) / $extentx) * $this->neededImageWidth;
        $pixPos_y = (($maxY - $rw_y) / $extenty) * $this->neededImageHeight;

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
