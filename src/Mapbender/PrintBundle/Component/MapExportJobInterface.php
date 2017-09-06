<?php


namespace Mapbender\PrintBundle\Component;

use Psr\Log\LoggerInterface;

interface MapExportJobInterface
{
    /**
     * @param MapLoaderInterface $loader
     * @param FeatureRendererInterface $featureRenderer
     * @param LoggerInterface|null $logger
     * @return resource (GD)
     */
    public function run(MapLoaderInterface $loader, FeatureRendererInterface $featureRenderer, LoggerInterface $logger= null);
}
