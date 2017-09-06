<?php


namespace Mapbender\PrintBundle\Component;

/**
 * Temporary hack to enforce loadMapTile method availability while moving code from
 * ImageExportService to other class(es).
 *
 * Will be removed ASAP after refactoring is completed.
 *
 * @deprecated
 * @internal
 *
 * @package Mapbender\PrintBundle\Component
 */
interface MapLoaderInterface
{
    /**
     *
     * @param string $baseUrl
     * @param integer $width
     * @param integer $height
     * @param float $opacity in [0;1]
     * @return resource (GD)
     * @throws \Exception if image resource could not be created
     */
    public function loadMapTile($baseUrl, $width, $height, $opacity=1.0);
}
