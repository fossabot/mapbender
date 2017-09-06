<?php


namespace Mapbender\PrintBundle\Component;

/**
 * Temporary hack to enforce drawFeatures method availability while moving code from
 * ImageExportService to other class(es).
 *
 * Will be removed ASAP after refactoring is completed.
 *
 * @deprecated
 * @internal
 *
 * @package Mapbender\PrintBundle\Component
 */
interface FeatureRendererInterface
{
    /**
     * @param MapExportCanvas $canvas target to draw on
     * @param array[] $geometries
     */
    public function drawFeatures($canvas, $geometries);
}
