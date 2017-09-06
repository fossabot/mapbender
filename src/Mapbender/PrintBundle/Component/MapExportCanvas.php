<?php


namespace Mapbender\PrintBundle\Component;


class MapExportCanvas
{
    protected $pixelWidth;
    protected $pixelHeight;
    protected $center;
    protected $extent;
    protected $gdResource;

    public function __construct($projectedCenter, $projectedExtent, $pixelWidth, $pixelHeight)
    {
        $this->center = $projectedCenter;
        $this->pixelWidth = $pixelWidth;
        $this->pixelHeight = $pixelHeight;
        $this->extent = $projectedExtent;
    }

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
}
