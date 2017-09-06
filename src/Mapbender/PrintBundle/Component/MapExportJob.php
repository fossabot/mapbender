<?php


namespace Mapbender\PrintBundle\Component;


use Psr\Log\LoggerInterface;

class MapExportJob implements MapExportJobInterface
{
    /** @var  MapExportCanvas */
    protected $canvas;
    protected $mapLayers;
    protected $geometries;

    protected function __construct($canvas, $mapLayers, $geometries)
    {
        $this->canvas = $canvas;
        $this->mapLayers = $mapLayers;
        $this->geometries = $geometries;
    }

    public static function factory($center, $extent, $pixelDimensions, $mapLayers, $geometries, $rotation=0)
    {
        if ($rotation) {
            return MapExportJobRotated::factory($center, $extent, $pixelDimensions, $mapLayers, $geometries, $rotation);
        } else {
            // @todo: if pixelDimensions exceed TBD thresholds, return a tiled job instead
            $canvas = new MapExportCanvas($center, $extent, $pixelDimensions['width'], $pixelDimensions['height']);
            return new static($canvas, $mapLayers, $geometries);
        }
    }

    /**
     * @param MapLoaderInterface $loader
     * @param FeatureRendererInterface $featureRenderer
     * @return resource (GD)
     */
    public function run(MapLoaderInterface $loader, FeatureRendererInterface $featureRenderer, LoggerInterface $logger=null)
    {
        $this->canvas->setLogger($logger);
        $this->canvas->addLayers($loader, $this->mapLayers, false);
        $featureRenderer->drawFeatures($this->canvas, $this->geometries);
        $image = $this->canvas->getImage();
        return $image;
    }
}
