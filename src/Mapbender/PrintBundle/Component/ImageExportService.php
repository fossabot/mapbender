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
class ImageExportService implements MapLoaderInterface
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

    protected $tempFilePrefix = 'mb_imgexp';
    protected $logPrefix;

    /**
     * Here we store information about the drawing canvas (!= output sizing)
     * This is bigger than ouptut for rotated rendering and will later be
     * clipped down.
     *
     * * center x, y (in projection system)
     * * extent width, height (in projection system)
     * * pixelWidth
     * * pixelHeight
     * @todo: this is a good candidate for a class that absorbs
     *        the transformation functions for geometry rendering
     */
    /** @var  MapExportCanvas */
    protected $mainMapCanvas;

    public function __construct($container)
    {
        $this->container = $container;
        $this->tempdir = sys_get_temp_dir();
        # Extract URL base path so we can later decide to let Symfony handle internal requests or make proper
        # HTTP connections.
        # NOTE: This is only possible in web, not CLI; "queued" printing uses CLI!
        if (php_sapi_name() != "cli") {
            $request = $this->container->get('request');
            $this->urlHostPath = $request->getHttpHost() . $request->getBaseURL();
        } else {
            $this->urlHostPath = null;
        }
        $this->resourceDir = $this->container->getParameter('kernel.root_dir') . '/Resources/MapbenderPrintBundle';
        $this->logPrefix = implode('', array_slice(explode('\\', get_class($this)), -1));
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

    public function export($content)
    {
        $this->setup(json_decode($content, true));

        $imageResource = $this->buildMainMapImage();
        $this->emitImageToBrowser($imageResource);
        imagedestroy($imageResource);
    }

    /**
     * @param array $configuration
     */
    private function setup($configuration)
    {
        $this->data = $configuration;
        $this->mainMapCanvas = $this->setupMainMapCanvas($configuration);
        $this->mainMapCanvas->setLogger($this->getLogger());
        $this->mapRequests = $this->filterMapLayers($configuration['requests']);
    }

    protected function setupMainMapCanvas($configuration)
    {
        return new MapExportCanvas(
            array(
                'x' => $configuration['centerx'],
                'y' => $configuration['centery'],
            ),
            array(
                'width' => $configuration['extentwidth'],
                'height' => $configuration['extentheight'],
            ),
            $configuration['width'],
            $configuration['height']
        );
    }

    /**
     * Extracts service base URLs by removing BBOX / WIDTH / HEIGHT params, forwards / populates `changeAxis` and
     * `opacity`. By default skips over inputs that do not have `type`="wms" set (use $acceptTypes=null to bypass).
     *
     * @param array[] $layersIn
     * @param string[]|null $acceptTypes null for unfiltered forwarding
     * @return array[]
     */
    protected function filterMapLayers($layersIn, $acceptTypes=array('wms'))
    {
        $formattedRequests = array();
        foreach ($layersIn as $i => $layer) {
            if ($acceptTypes !== null && !in_array($layer['type'], $acceptTypes)) {
                continue;
            }

            $formattedRequests[$i] = array(
                'baseUrl'    => $this->clearExtentParamsFromUrl($layer['url']),
                'opacity'    => (isset($layer['opacity']) ? $layer['opacity'] : 1.0),
                'changeAxis' => !empty($layer['changeAxis']),
            );
        }
        return $formattedRequests;
    }

    /**
     * Build the combined main map image, WMS + vector layers
     * @return resource GD image
     */
    protected function buildMainMapImage()
    {
        $this->mainMapCanvas->addLayers($this, $this->mapRequests, false);
        return $this->mainMapCanvas->getImage();
    }

    /**
     * Convert a GD image to true-color RGBA and write it back to the file
     * system.
     *
     * @param resource $imageResource source image
     * @param float $opacity in [0;1]
     * @return resource (GD)
     */
    protected static function forceToRgba($imageResource, $opacity)
    {
        $width = imagesx($imageResource);
        $height = imagesy($imageResource);

        // Make sure input image is truecolor with alpha, regardless of input mode!
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $imageResource, 0, 0, 0, 0, $width, $height, $width, $height);

        // Taking the painful way to alpha blending. Stupid PHP-GD
        if ($opacity < 1) {
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
        return $image;
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
     * @param Response $response
     * @return resource GD image
     * @throws \Exception if image could not be built from $response
     */
    protected function serviceResponseToGdImage($response)
    {
        $resource = imagecreatefromstring($response->getContent());
        if (!$resource) {
            $e = error_get_last();
            /**
             * @todo: throw more specific exception type
             *        Exception should store $response in full
             */
            $em = "Image conversion failed with " . $e['message']
                . " (Content-Type: " . var_export($response->headers->get('Content-Type'), true) . "; "
                . " (Status Code: {$response->getStatusCode()})";
            throw new \Exception($em);
        }
        return $resource;
    }

    /**
     * @param resource $image GD image
     */
    protected function emitImageToBrowser($image)
    {
        if ($this->data['format'] == 'png') {
            header("Content-type: image/png");
            header("Content-Disposition: attachment; filename=export_" . date("YmdHis") . ".png");
            //header('Content-Length: ' . filesize($file));
            imagepng($image);
        } else {
            header("Content-type: image/jpeg");
            header("Content-Disposition: attachment; filename=export_" . date("YmdHis") . ".jpg");
            //header('Content-Length: ' . filesize($file));
            imagejpeg($image, null, 85);
        }
    }

    /**
     * @return array[]
     */
    protected function getGeometryLayers()
    {
        $geoLayers = array();
        if (isset($this->data['vectorLayers'])){
            foreach ($this->data['vectorLayers'] as $idx => $layer){
                $vectorLayers[] = json_decode($this->data['vectorLayers'][$idx], true);
            }
        }
        return $geoLayers;
    }

    /**
     * @param resource $targetImage GD image to draw on
     */
    protected function drawFeatures($targetImage)
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

    protected function drawPolygon($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
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
                imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
                imagepolygon($image, $points, count($ring), $color);
            }
        }
    }


    protected function drawMultiPolygon($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        foreach ($geometry['coordinates'] as $element) {
            foreach($element as $ring) {
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
                    imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
                    imagepolygon($image, $points, count($ring), $color);
                }
            }
        }
    }



    protected function drawLineString($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        if ($style['strokeWidth'] == 0) {
            return;
        }
        imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);

        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
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

    protected function drawMultiLineString($geometry, $image)
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

    protected function drawPoint($geometry, $image, $skipOnEmptyLabel=true)
    {
        $style = $this->getStyle($geometry);
        $c = $geometry['coordinates'];
        $resizeFactor = $this->getResizeFactor();

        $p = $this->realWorld2mapPos($c[0], $c[1]);

        if (isset($style['label'])) {
            $this->drawHaloText($image, $p, 14, $style['label'], $style);
            /**
             * @todo: figure out if this was a mistake
             *        ImageExportService skips rendering the actual point if
             *        a label exists; PrintService still renders the point, but
             *        after the label (potentially obscuring it)
             */
            if ($skipOnEmptyLabel) {
                return;
            }
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
     * Transform an x / y coordinate from a map projection system into a pixel
     * offset.
     *
     * @param float $rw_x
     * @param float $rw_y
     * @return float[] pixel offset x / y
     */
    protected function realWorld2mapPos($rw_x, $rw_y)
    {
        $canvas = $this->mainMapCanvas;
        return $canvas->unproject($rw_x, $rw_y);
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

    /**
     *
     * @param string $url
     * @param float $opacity in [0;1]
     * @return resource (GD)
     * @throws \Exception if image resource could not be created
     */
    public function fetchImage($url, $opacity=1.0)
    {
        $response = $this->mapRequest($url);
        try {
            $resource = $this->serviceResponseToGdImage($response);
        } catch (\Exception $e) {
            $this->getLogger()->error("{$this->logPrefix} while processing response from " . var_export($url, true) . ":\n\t{$e->getMessage()}");
            throw $e;
        }
        $imageRGBA = $this->forceToRgba($resource, $opacity);
        imagedestroy($resource);
        return $imageRGBA;
    }

    /**
     * Downloads an image from given $url and stores it in the file system at $path.
     *
     * @param string $path
     * @param string $url
     * @param float $opacity in [0;1]
     */
    protected function storeImage($path, $url, $opacity=1.0)
    {
        $imageRGBA  = $this->fetchImage($url, $opacity);
        imagepng($imageRGBA, $path);
        imagedestroy($imageRGBA);
    }

    /**
     * Generate a semi-random name for a temporary file.
     *
     * @param string|null $addPrefix will be concatenated with class-specific prefix
     * @return string absolute filesystem path
     */
    protected function generateTempName($addPrefix = null)
    {
        $prefix = "{$this->tempFilePrefix}{$addPrefix}";
        return tempnam($this->tempdir, $prefix);
    }

    /**
     * Remove BBOX, WIDTH, HEIGHT params from given url, so we can freely substitute calculated values
     *
     * @todo: this method definitely belongs somewhere else (WMS bundle utils most likely)
     *
     * @param string $url
     * @return string
     */
    public static function clearExtentParamsFromUrl($url)
    {
        // HACK: Assume we don't need to retain anything following or between these params :\
        // @todo: parse, process, reconstruct URL properly
        $stripParams = array('bbox', 'width', 'height');
        foreach ($stripParams as $stripParam) {
            $stripResult = stristr($url, "&{$stripParam}", true);
            if ($stripResult !== false) {
                $url = $stripResult;
            }
        }
        if (false === strpos($url, '?')) {
            $url = "{$url}?";
        }
        return $url;
    }
}
