<?php
namespace Mapbender\PrintBundle\Component;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use OwsProxy3\CoreBundle\Component\CommonProxy;
use OwsProxy3\CoreBundle\Component\ProxyQuery;
use OwsProxy3\CoreBundle\Component\WmsProxy;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Image export service.
 *
 * @author Stefan Winkelmann
 */
class ImageExportService
{
    /** @var ContainerInterface */
    protected $container;
    /** @var string */
    protected $tempdir;
    /** @var string|null */
    protected $urlHostPath;
    /** @var  array */
    protected $data;
    /** @var array */
    protected $mapRequests = array();
    /** @var string */
    protected $resourceDir;

    public function __construct($container)
    {
        $this->container = $container;
        $this->tempdir = sys_get_temp_dir();
        $this->urlHostPath = $this->container->get('request')->getHttpHost() . $this->container->get('request')->getBaseURL();
        $this->resourceDir = $this->container->getParameter('kernel.root_dir') . '/Resources/MapbenderPrintBundle';
        $this->reset();
    }

    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container->get("logger");
        return $logger;
    }

    /**
     * Clean up internally modified / collected state
     */
    protected function reset()
    {
        $this->mapRequests = array();
    }

    public function export($content)
    {
        $this->reset();
        $this->data = json_decode($content, true);

        foreach ($this->data['requests'] as $i => $layer) {
            if ($layer['type'] != 'wms') {
                continue;
            }
            $baseUrl = strstr($layer['url'], '&WIDTH', true);
            $width = '&WIDTH=' . $this->data['width'];
            $height = '&HEIGHT=' . $this->data['height'];
            $this->mapRequests[$i] = $baseUrl . $width . $height;
        }

        if(isset($this->data['vectorLayers'])){
            foreach ($this->data['vectorLayers'] as $idx => $layer){
                $this->data['vectorLayers'][$idx] = json_decode($this->data['vectorLayers'][$idx], true);
            }
        }

        $imagePath = $this->getImages();
        $this->emitImageToBrowser($imagePath);
        unlink($imagePath);
    }

    /**
     * Collect and merge WMS tiles and vector layers into a PNG file.
     *
     * @return string path to merged PNG file
     */
    private function getImages()
    {
        $temp_names = array();
        foreach ($this->mapRequests as $k => $url) {
            $this->getLogger()->debug("Image Export Request Nr.: " . $k . ' ' . $url);

            $mapRequestResponse = $this->mapRequest($url);

            $imageName = tempnam($this->tempdir, 'mb_imgexp');
            $temp_names[] = $imageName;
            $rawImage = $this->serviceResponseToGdImage($imageName, $mapRequestResponse);

            if ($rawImage !== null) {
                $this->forceToRgba($imageName, $rawImage, $this->data['requests'][$k]['opacity']);
                $width = imagesx($rawImage);
                $height = imagesy($rawImage);
            }
        }
        $finalImageName = tempnam($this->tempdir, 'mb_imgexp_merged');
        $this->mergeImages($finalImageName, $temp_names, $width, $height);
        if (isset($this->data['vectorLayers'])) {
            $this->drawFeatures($finalImageName);
        }
        return $finalImageName;
    }

    /**
     * Copy PNGs from given inputNames (in order) onto a new image of given
     * dimensions, and store the resulting merged PNG at $outputName.
     * All valid input PNGs will be deleted!
     *
     * @param string $outputName
     * @param string[] $inputNames
     * @param integer $width
     * @param integer $height
     */
    protected function mergeImages($outputName, $inputNames, $width, $height)
    {
        // create final merged image
        $mergedImage = imagecreatetruecolor($width, $height);
        $bg = ImageColorAllocate($mergedImage, 255, 255, 255);
        imagefilledrectangle($mergedImage, 0, 0, $width, $height, $bg);
        foreach ($inputNames as $inputName) {
            $src = @imagecreatefrompng($inputName);
            if ($src) {
                $src = imagecreatefrompng($inputName);
                imagecopy($mergedImage, $src, 0, 0, 0, 0, $width, $height);
                imagedestroy($src);
            }
            unlink($inputName);
        }
        imagepng($mergedImage, $outputName);
    }

    /**
     * Convert a GD image to true-color RGBA and write it back to the file
     * system.
     *
     * @param string $imageName will be overwritten
     * @param resource $imageResource source image
     * @param float $opacity in [0;1]
     */
    protected function forceToRgba($imageName, $imageResource, $opacity)
    {
        $width = imagesx($imageResource);
        $height = imagesy($imageResource);

        // Make sure input image is truecolor with alpha, regardless of input mode!
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $imageResource, 0, 0, 0, 0, $width, $height, $width, $height);

        // Taking the painful way to alpha blending. Stupid PHP-GD
        if (1.0 !== $opacity) {
            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $colorIn = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $alphaOut = 127 - (127 - $colorIn['alpha']) * $opacity;

                    $colorOut = imagecolorallocatealpha(
                        $image,
                        $colorIn['red'],
                        $colorIn['green'],
                        $colorIn['blue'],
                        $alphaOut);
                    imagesetpixel($image, $x, $y, $colorOut);
                    imagecolordeallocate($image, $colorOut);
                }
            }
        }
        imagepng($image, $imageName);
    }

    /**
     * Query a (presumably) WMS service $url and return the Response object.
     *
     * @param string $url
     * @return Response
     */
    protected function mapRequest($url)
    {
        // find urls from this host (tunnel connection for secured services)
        $parsed   = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : $this->container->get('request')->getHttpHost();
        $hostpath = $host . $parsed['path'];
        $pos      = strpos($hostpath, $this->urlHostPath);
        if ($pos === 0 && ($routeStr = substr($hostpath, strlen($this->urlHostPath))) !== false) {
            $attributes = $this->container->get('router')->match($routeStr);
            $gets       = array();
            parse_str($parsed['query'], $gets);
            $subRequest = new Request($gets, array(), $attributes, array(), array(), array(), '');
            /** @var HttpKernelInterface $kernel */
            $kernel = $this->container->get('http_kernel');
            $response = $kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        } else {
            $proxyQuery = ProxyQuery::createFromUrl($url);
            try {
                $serviceType = strtolower($proxyQuery->getServiceType());
            } catch (\Exception $e) {
                // fired when null "content" is loaded as an XML document...
                $serviceType = null;
            }
            $proxyConfig = $this->container->getParameter('owsproxy.proxy');
            switch ($serviceType) {
                case "wms":
                    /** @var EventDispatcherInterface $eventDispatcher */
                    $eventDispatcher = $this->container->get('event_dispatcher');
                    $proxy = new WmsProxy($eventDispatcher, $proxyConfig, $proxyQuery, $this->getLogger());
                    break;
                default:
                    $proxy = new CommonProxy($proxyConfig, $proxyQuery, $this->getLogger());
                    break;
            }
            /** @var \Buzz\Message\Response $buzzResponse */
            $buzzResponse = $proxy->handle();
            $response = $this->convertBuzzResponse($buzzResponse);
        }
        return $response;
    }

    /**
     * Convert a Buzz Response to a Symfony HttpFoundation Response
     *
     * @todo: This belongs in owsproxy; it's the only part of Mapbender that uses Buzz
     *
     * @param \Buzz\Message\Response $buzzResponse
     * @return Response
     */
    public static function convertBuzzResponse($buzzResponse)
    {
        // adapt header formatting: Buzz uses a flat list of lines, HttpFoundation expects a name: value mapping
        $headers = array();
        foreach ($buzzResponse->getHeaders() as $headerLine) {
            $parts = explode(':', $headerLine, 2);
            if (count($parts) == 2) {
                $headers[$parts[0]] = $parts[1];
            }
        }
        $response = new Response($buzzResponse->getContent(), $buzzResponse->getStatusCode(), $headers);
        $response->setProtocolVersion($buzzResponse->getProtocolVersion());
        $statusText = $buzzResponse->getReasonPhrase();
        if ($statusText) {
            $response->setStatusCode($buzzResponse->getStatusCode(), $statusText);
        }
        return $response;
    }

    /**
     * Converts a http response to a GD image, respecting the mimetype.
     *
     * @param string $storagePath for temp file storage
     * @param Response $response
     * @return resource|null GD image or null on failure
     */
    protected function serviceResponseToGdImage($storagePath, $response)
    {
        file_put_contents($storagePath, $response->getContent());
        $rawContentType = trim($response->headers->get('content-type'));
        $contentTypeMatches = array();
        if (preg_match('#^\s*(image/[\w]+)#', $rawContentType, $contentTypeMatches) && !empty($contentTypeMatches[1])) {
            $matchedContentType = $contentTypeMatches[1];
        } else {
            $matchedContentType = $rawContentType;
        }
        switch ($matchedContentType) {
            case "image/png":
                return imagecreatefrompng($storagePath);
                break;
            case "image/jpeg":
                return imagecreatefromjpeg($storagePath);
                break;
            case "image/gif":
                return imagecreatefromgif($storagePath);
                break;
            default:
                $message = 'Unhandled mimetype ' . var_export($matchedContentType, true);
                $this->getLogger()->warning($message);
                // throw new \RuntimeException($message);
                return null;
        }
    }

    /**
     * @param string $imagePath
     */
    protected function emitImageToBrowser($imagePath)
    {
        $finalImage = imagecreatefrompng($imagePath);
        if ($this->data['format'] == 'png') {
            header("Content-type: image/png");
            header("Content-Disposition: attachment; filename=export_" . date("YmdHis") . ".png");
            //header('Content-Length: ' . filesize($file));
            imagepng($finalImage);
        } else {
            header("Content-type: image/jpeg");
            header("Content-Disposition: attachment; filename=export_" . date("YmdHis") . ".jpg");
            //header('Content-Length: ' . filesize($file));
            imagejpeg($finalImage, null, 85);
        }
    }

    /**
     * @return array[]
     */
    protected function getGeometryLayers()
    {
        return $this->data['vectorLayers'];
    }

    private function drawFeatures($finalImageName)
    {
        $image = imagecreatefrompng($finalImageName);
        imagesavealpha($image, true);
        imagealphablending($image, true);

        foreach ($this->getGeometryLayers() as $layer) {
            foreach($layer['geometries'] as $geometry) {
                $renderMethodName = 'draw' . $geometry['type'];
                if(!method_exists($this, $renderMethodName)) {
                    continue;
                    //throw new \RuntimeException('Can not draw geometries of type "' . $geometry['type'] . '".');
                }
                $this->$renderMethodName($geometry, $image);
            }
        }
        imagepng($image, $finalImageName);
    }

    protected function getColor($color, $alpha, $image)
    {
        list($r, $g, $b) = CSSColorParser::parse($color);

        if(0 == $alpha) {
            return ImageColorAllocate($image, $r, $g, $b);
        } else {
            $a = (1 - $alpha) * 127.0;
            return imagecolorallocatealpha($image, $r, $g, $b, $a);
        }
    }

    private function drawPolygon($geometry, $image)
    {
        $style = $this->getStyle($geometry);
        foreach($geometry['coordinates'] as $ring) {
            if(count($ring) < 3) {
                continue;
            }

            $points = array();
            foreach($ring as $c) {
                $p = $this->realWorld2mapPos($c[0], $c[1]);
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
                imagesetthickness($image, $style['strokeWidth'] * $this->getResizeFactor());
                imagepolygon($image, $points, count($ring), $color);
            }
        }
    }

    private function drawMultiPolygon($geometry, $image)
    {
        $style = $this->getStyle($geometry);
        foreach($geometry['coordinates'][0] as $ring) {
            if(count($ring) < 3) {
                continue;
            }

            $points = array();
            foreach($ring as $c) {
                $p = $this->realWorld2mapPos($c[0], $c[1]);
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
                imagesetthickness($image, $style['strokeWidth'] * $this->getResizeFactor());
                imagepolygon($image, $points, count($ring), $color);
            }
        }
    }

    private function drawLineString($geometry, $image)
    {
        $style = $this->getStyle($geometry);
        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
        imagesetthickness($image, $style['strokeWidth'] * $this->getResizeFactor());

        for($i = 1; $i < count($geometry['coordinates']); $i++) {
            $from = $this->realWorld2mapPos(
                $geometry['coordinates'][$i - 1][0],
                $geometry['coordinates'][$i - 1][1]);
            $to = $this->realWorld2mapPos(
                $geometry['coordinates'][$i][0],
                $geometry['coordinates'][$i][1]);

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
                $from = $this->realWorld2mapPos(
                    $coords[$i - 1][0],
                    $coords[$i - 1][1]);
                $to = $this->realWorld2mapPos(
                    $coords[$i][0],
                    $coords[$i][1]);
                imageline($image, $from[0], $from[1], $to[0], $to[1], $color);
            }
        }
    }

    /**
     * @param resource $image GD image
     * @param float[] $center x/y
     * @param $fontSize
     * @param $text
     * @param array $style
     */
    protected function drawHaloText($image, $center, $fontSize, $text, $style = array())
    {
        // use color / background from style, default to black text on white halo outline
        if (!empty($style['fontColor'])) {
            $color = $this->getColor($style['fontColor'], 1, $image);
        } else {
            $color = $this->getColor('#ff0000', 1, $image);
        }
        if (!empty($style['labelOutlineColor'])) {
            $bgcolor = $this->getColor($style['labelOutlineColor'], 1, $image);
        } else {
            $bgcolor = $this->getColor('#ffffff', 1, $image);
        }
        $fontPath = $this->resourceDir . '/fonts/';
        $font = $fontPath . 'OpenSans-Bold.ttf';
        $haloOffset = $this->getResizeFactor();
        $fontSize *= $this->getResizeFactor();
        imagettftext($image, $fontSize, 0,
            $center[0], $center[1]+$haloOffset, $bgcolor, $font, $text);
        imagettftext($image, $fontSize, 0,
            $center[0], $center[1]-$haloOffset, $bgcolor, $font, $text);
        imagettftext($image, $fontSize, 0,
            $center[0]-$haloOffset, $center[1], $bgcolor, $font, $text);
        imagettftext($image, $fontSize, 0,
            $center[0]+$haloOffset, $center[1], $bgcolor, $font, $text);
        imagettftext($image, $fontSize, 0,
            $center[0], $center[1], $color, $font, $style['label']);
    }

    private function drawPoint($geometry, $image)
    {
        $style = $this->getStyle($geometry);
        $c = $geometry['coordinates'];

        $p = $this->realWorld2mapPos($c[0], $c[1]);

        if(isset($style['label'])){
            $this->drawHaloText($image, $p, 14, $style['label'], $style);
            return;
            //??? this was here before. Label means no point is rendered?
        }

        $radius = $style['pointRadius'];
        // Filled circle
        if($style['fillOpacity'] > 0){
            $color = $this->getColor(
                $style['fillColor'],
                $style['fillOpacity'],
                $image);
            imagefilledellipse($image, $p[0], $p[1], 2*$radius, 2*$radius, $color);
        }
        // Circle border
        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
        imageellipse($image, $p[0], $p[1], 2*$radius, 2*$radius, $color);
    }

    /**
     * @return float[] pixel offset x / y
     */
    private function realWorld2mapPos($rw_x,$rw_y)
    {
        $map_width = $this->data['extentwidth'];
        $map_height = $this->data['extentheight'];
        $centerx = $this->data['centerx'];
        $centery = $this->data['centery'];

        $height = $this->data['height'];
        $width = $this->data['width'];

        $minX = $centerx - $map_width * 0.5;
        $minY = $centery - $map_height * 0.5;
        $maxX = $centerx + $map_width * 0.5;
        $maxY = $centery + $map_height * 0.5;

        $extentx = $maxX - $minX ;
        $extenty = $maxY - $minY ;

        $pixPos_x = (($rw_x - $minX) / $extentx) * $width;
        $pixPos_y = (($maxY - $rw_y) / $extenty) * $height;

        $pixPos = array($pixPos_x, $pixPos_y);

        return $pixPos;
    }

    /**
     * Get the target resolution in DPI
     *
     * @return int
     */
    protected function getQualityDpi()
    {
        return 72;
    }

    /**
     * Returns a scale factor for the thickness of vector
     * outlines.
     * See  https://github.com/mapbender/mapbender/pull/575
     *
     * @return int|float
     */
    protected function getResizeFactor()
    {
        return $this->getQualityDpi() / 72;
    }

    /**
     * @param array $geometry
     * @return array
     */
    protected function getStyle($geometry)
    {
        return $geometry['style'];
    }
}
