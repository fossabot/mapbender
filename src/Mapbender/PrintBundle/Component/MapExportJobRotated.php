<?php


namespace Mapbender\PrintBundle\Component;


use Psr\Log\LoggerInterface;

class MapExportJobRotated implements MapExportJobInterface
{
    /** @var  MapExportJob */
    protected $unrotatedJob;
    /** @var  float */
    protected $rotation;
    /** @var  integer[] */
    protected $targetPixelDimensions;

    private function __construct($unrotatedJob, $targetPixelDimensions, $rotation)
    {
        $this->unrotatedJob = $unrotatedJob;
        $this->targetPixelDimensions = $targetPixelDimensions;
        $this->rotation = $rotation;
    }

    public static function factory($center, $extent, $pixelDimensions, $mapLayers, $geometries, $rotation)
    {
        // build a larger, unrotated export job which we will later rotate and crop
        // calculate expanded pixel dimensions and extent for nested job
        $innerWidth = round(abs(sin(deg2rad($rotation)) * $pixelDimensions['height']) +
            abs(cos(deg2rad($rotation)) * $pixelDimensions['width']));
        $innerHeight = round(abs(sin(deg2rad($rotation)) * $pixelDimensions['width']) +
            abs(cos(deg2rad($rotation)) * $pixelDimensions['height']));

        // calculate needed bbox
        $innerExtentWidth = abs(sin(deg2rad($rotation)) * $extent['height']) +
            abs(cos(deg2rad($rotation)) * $extent['width']);
        $innerExtentHeight = abs(sin(deg2rad($rotation)) * $extent['width']) +
            abs(cos(deg2rad($rotation)) * $extent['height']);
        $innerExtent = array(
            'width' => $innerExtentWidth,
            'height' => $innerExtentHeight,
        );
        $innerPixeldDimensions = array(
            'width' => $innerWidth,
            'height' => $innerHeight,
        );

        $unrotatedJob = MapExportJob::factory($center, $innerExtent, $innerPixeldDimensions,
            $mapLayers, $geometries,
            0);
        return new static($unrotatedJob, $pixelDimensions, $rotation);
    }

    /**
     * @param MapLoaderInterface $loader
     * @param FeatureRendererInterface $featureRenderer
     * @return resource (GD)
     */
    public function run(MapLoaderInterface $loader, FeatureRendererInterface $featureRenderer, LoggerInterface $logger=null)
    {
        $tempImage = $this->unrotatedJob->run($loader, $featureRenderer, $logger);
        $transColor = imagecolorallocatealpha($tempImage, 255, 255, 255, 127);
        $rotatedImage = imagerotate($tempImage, $this->rotation, $transColor);
        imagealphablending($rotatedImage, false);
        imagesavealpha($rotatedImage, true);

        $rotatedWidth = imagesx($rotatedImage);
        $rotatedHeight = imagesy($rotatedImage);

        $targetWidth    = $this->targetPixelDimensions['width'];
        $targetHeight   = $this->targetPixelDimensions['height'];
        $newx = round(($rotatedWidth - $targetWidth ) / 2);
        $newy = round(($rotatedHeight - $targetHeight ) / 2);

        $clippedImage = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($clippedImage, false);
        imagesavealpha($clippedImage, true);
        imagecopy($clippedImage, $rotatedImage, 0, 0, $newx, $newy,
            $targetWidth, $targetHeight);

        return $clippedImage;
    }
}
