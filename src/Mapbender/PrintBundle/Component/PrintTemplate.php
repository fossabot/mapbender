<?php


namespace Mapbender\PrintBundle\Component;


class PrintTemplate
{
    /** @var string */
    protected $pdfPath;

    protected $odgContent;
    protected $odgStyles;

    protected $customShapes;

    public function __construct($resourceDir, $name)
    {
        $pdfPath = rtrim($resourceDir, '/') . "/{$name}.pdf";
        $odgPath = rtrim($resourceDir, '/') . "/{$name}.odg";
        $this->pdfPath = $this->resolveToReadableFile($pdfPath);
        $odgPath = $this->resolveToReadableFile($odgPath);
        $this->odgContent = OdgParser::parseZipXmlMember($odgPath, 'content.xml');
        $this->odgStyles = OdgParser::parseZipXmlMember($odgPath, 'styles.xml');

        $this->customShapes = OdgParser::extractCustomShapes($this->odgContent);
    }

    /**
     * @return string
     */
    public function getPdfPath()
    {
        return $this->pdfPath;
    }

    /**
     * @param string $name
     * @return array|null
     */
    public function getCustomShape($name)
    {
        if (!empty($this->customShapes[$name])) {
            return $this->customShapes[$name];
        } else {
            return null;
        }
    }

    /**
     * Checks if given $inputPath is a readable file, after resolving symlinks.
     *
     * @param string $inputPath
     * @return string
     */
    protected static function resolveToReadableFile($inputPath)
    {
        $resolvedPath = $inputPath;
        while (is_link($resolvedPath)) {
            $resolvedPath = readlink($resolvedPath);
        }
        if (!is_file($resolvedPath) || !(is_readable($resolvedPath))) {
            $message = "Can't access $resolvedPath";
            if ($resolvedPath != $inputPath) {
                $message .= " (originally $inputPath)";
            }
            throw new \RuntimeException($message);
        }
        return $resolvedPath;
    }
}
