<?php


namespace Mapbender\PrintBundle\Component;


use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;

class MapExportCanvas
{
    protected $pixelWidth;
    protected $pixelHeight;
    /** @var  float[] with at least `x` and `y` keys */
    protected $center;
    /** @var  float[] with at least `width` and `height` keys */
    protected $extent;
    /** @var  resource (GD) lazy-initialized by getImage! */
    protected $gdResource;
    /** @var  LoggerInterface */
    protected $logger;

    /**
     *
     * @param float[] $projectedCenter must have values for `x` and `y` keys
     * @param float[] $projectedExtent must have values for `width` and `height` keys
     * @param integer $pixelWidth
     * @param integer $pixelHeight
     */
    public function __construct($projectedCenter, $projectedExtent, $pixelWidth, $pixelHeight)
    {
        $this->center = $projectedCenter;
        $this->pixelWidth = $pixelWidth;
        $this->pixelHeight = $pixelHeight;
        $this->extent = $projectedExtent;
        $this->setLogger(null);
    }

    public function __destruct()
    {
        if ($this->gdResource) {
            imagedestroy($this->gdResource);
        }
    }

    /**
     * Transform given x/y from projection to pixel space. Return value is an array with x/y keys.
     * @todo: input should be in same format as return
     * @todo: minX/minY and scale factors can be precalculated in ctor. Bbox method would also benefit from precalc.
     *
     * @param float $x
     * @param float $y
     * @return float[]
     */
    public function unproject($x, $y)
    {
        $extentWidth  = $this->extent['width'];
        $extentHeight = $this->extent['height'];
        $centerx   = $this->center['x'];
        $centery   = $this->center['y'];

        $minX = $centerx - $extentWidth * 0.5;
        $maxY = $centery + $extentHeight * 0.5;

        $pixPos_x = (($x - $minX) / $extentWidth) * $this->pixelWidth;
        $pixPos_y = (($maxY - $y) / $extentHeight) * $this->pixelHeight;

        return array($pixPos_x, $pixPos_y);
    }

    /**
     * Format extent for use in a WMS request BBOX=... parameter.
     *
     * @param bool $latlon to flip x/y ordering (default false)
     * @return string
     */
    public function getBboxParam($latlon=false)
    {
        $minX = $this->center['x'] - $this->extent['width'] * .5;
        $maxX = $this->center['x'] + $this->extent['width'] * .5;
        $minY = $this->center['y'] - $this->extent['height'] * .5;
        $maxY = $this->center['y'] + $this->extent['height'] * .5;
        if ($latlon) {
            // inverted coordinate order for certain EPSGs in WMS1.3.0
            return "{$minY},{$minX},{$maxY},{$maxX}";
        } else {
            // mathematically sane coordinate order
            return "{$minX},{$minY},{$maxX},{$maxY}";
        }
    }

    /**
     * Download WMS images and blend them on top of the canvas (in order given).
     *
     * @param MapLoaderInterface $loader
     * @param array[] $layerSpecs each entry should contain values for keys baseUrl, opacity
     *      BBOX, WIDTH and HEIGHT params will be added, and SHOULD NOT be present in inputs
     * @param boolean $ignoreErrors to swallow exceptions
     * @throws \Exception
     */
    public function addLayers(MapLoaderInterface $loader, $layerSpecs, $ignoreErrors=false)
    {
        $mergeTarget = $this->getImage();
        $width = $this->pixelWidth;
        $height = $this->pixelHeight;
        foreach ($layerSpecs as $i => $layerSpec) {
            $url = $layerSpec['baseUrl'];
            $url .= "&BBOX=" . $this->getBboxParam(!empty($layerSpec['changeAxis']));
            $url .= "&WIDTH=" . intval($width) . "&HEIGHT=" . intval($height);

            $this->logger->debug("addLayers Request Nr.: " . $i . ' ' . $url);

            try {
                $rgbaImage = $loader->fetchImage($url, $layerSpec['opacity']);
            } catch (\Exception $e) {
                if ($ignoreErrors) {
                    continue;
                } else {
                    throw $e;
                }
            }
            imagecopy($mergeTarget, $rgbaImage, 0, 0, 0, 0, $width, $height);
            imagedestroy($rgbaImage);
        }
    }

    /**
     * @return resource GD image
     */
    public function getImage()
    {
        if (!$this->gdResource) {
            $w = $this->pixelWidth;
            $h = $this->pixelHeight;
            $this->gdResource = imagecreatetruecolor($w, $h);
            $bg = ImageColorAllocate($this->gdResource, 255, 255, 255);
            imagefilledrectangle($this->gdResource, 0, 0, $w, $h, $bg);
            imagecolordeallocate($this->gdResource, $bg);
        }
        return $this->gdResource;
    }

    public function setLogger(LoggerInterface $logger=null)
    {
        if ($logger) {
            $this->logger = $logger;
        } else {
            $this->logger = new NullLogger();
        }
    }
}
