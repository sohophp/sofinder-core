<?php
declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

use SohoPHP\SoFinder\Contract\ImageActionProcessorInterface;
use SohoPHP\SoFinder\Contract\ImageCapabilityProviderInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Image\GdImageProcessor;
use SohoPHP\SoFinder\Image\ImageFormatRegistry;

/** Configurable GD action pipeline; decode once and encode the complete basic edit once. */
final class GdActionImageProcessor implements ImageActionProcessorInterface, ImageCapabilityProviderInterface
{
    private readonly GdImageProcessor $inspection;
    public function __construct(private readonly ImageActionOptions $options = new ImageActionOptions(), private readonly int $maximumPixels = 50_000_000)
    {
        if ($maximumPixels < 1) throw new \InvalidArgumentException('Image pixel limit must be positive.');
        $this->inspection = new GdImageProcessor(maximumPixels: $maximumPixels);
    }

    public function supports(string $mimeType): bool
    {
        return in_array((new ImageFormatRegistry())->formatForMime($mimeType), $this->options->formats, true)
            && $this->inspection->supports($mimeType);
    }

    public function dimensions(string $source): array
    {
        $info = @getimagesize($source);
        if (!is_array($info) || !$this->supports($info['mime'])) $this->fail('The image format is invalid.', 'invalid_image', 415);
        $this->checkSize($info[0], $info[1]);
        // Coordinate orientation is an explicit processor option.
        return ImageOrientation::dimensions($info[0], $info[1], $source, $info['mime'], $this->options->autoOrientation);
    }

    public function validate(string $source): array
    {
        [$image] = $this->load($source);
        // GIF's logical canvas can exceed the decoded first-frame rectangle.
        return $this->dimensions($source);
    }

    public function isAnimated(string $source): bool { return $this->inspection->isAnimated($source); }

    public function supportedActions(): array { return $this->options->actions; }

    public function resizeLimits(): array { return ['width' => $this->options->maxWidth, 'height' => $this->options->maxHeight]; }

    public function capabilities(): array
    {
        $result = [];
        foreach ((new ImageFormatRegistry())->definitions() as $format => $definition) {
            $available = $this->supports($definition['mimes'][0]);
            $result[] = ['format' => $format, 'extensions' => $definition['extensions'], 'mimes' => $definition['mimes'],
                'processor' => $available ? 'gd' : '', 'read' => $available,
                'edit' => $available && $this->options->actions !== [] && in_array($format, $this->options->editableFormats, true),
                'thumbnail' => $available, 'webEmbeddable' => $available && $definition['web']];
        }
        return $result;
    }

    public function isWebEmbeddable(string $mimeType): bool
    {
        return $this->supports($mimeType) && (new ImageFormatRegistry())->isWebEmbeddableMime($mimeType);
    }

    public function supportsExtension(string $extension): bool
    {
        $formats = new ImageFormatRegistry();
        $format = $formats->formatForExtension($extension);
        $mime = $format === null ? null : $formats->canonicalMime($format);
        return $mime !== null && $this->supports($mime);
    }

    public function driver(): string { return 'gd'; }
    public function cacheVersion(): string { return hash('sha256', 'gd-actions-v1|' . json_encode([$this->options, $this->maximumPixels], JSON_THROW_ON_ERROR)); }

    public function allowsAnimatedEditing(string $source): bool
    {
        $info = @getimagesize($source);
        // First-frame editing must be explicitly enabled by configuration.
        return $this->options->animatedEditing === 'first_frame' && is_array($info) && $this->supports($info['mime']);
    }

    public function thumbnail(string $source, string $destination, int $width, int $height): void
    {
        [$image, $mime] = $this->load($source);
        $dimensions = $this->dimensions($source);
        $image = $this->resize($image, $mime, $width, $height, $dimensions['width'], $dimensions['height']);
        if (!imagepng($image, $destination)) $this->fail('Cannot save the thumbnail.');
    }

    public function transform(string $source, string $destination, int $rotation, int $width, int $height, int $quality = 88): void
    {
        $actions = [];
        if ($rotation !== 0) $actions[] = ['type' => 'rotate', 'degrees' => $rotation];
        if ($width !== 0 || $height !== 0 || $actions === []) $actions[] = ['type' => 'resize', 'width' => $width, 'height' => $height];
        $this->process($source, $destination, $actions, $quality);
    }

    public function crop(string $source, string $destination, int $x, int $y, int $width, int $height, int $quality = 88): void
    {
        $this->process($source, $destination, [['type' => 'crop', 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]], $quality);
    }

    public function applyActions(string $source, string $destination, array $actions): void
    {
        $this->process($source, $destination, $actions);
    }

    /** @param list<array<string,mixed>> $actions */
    private function process(string $source, string $destination, array $actions, ?int $encodingQuality = null): void
    {
        if ($encodingQuality !== null && ($encodingQuality < 1 || $encodingQuality > 100)) $this->fail('Image quality is out of range.', 'invalid_image_quality', 422);
        if ($source === $destination) $this->fail('Image work files must be separate.', 'invalid_image_input', 422);
        if ($actions === [] || count($actions) > 10) $this->fail('Invalid image actions.', 'invalid_image_actions', 422);
        if ($this->isAnimated($source) && !$this->allowsAnimatedEditing($source)) $this->fail('Animated image editing is disabled.', 'animated_image_edit_unsupported', 415);
        [$image, $mime] = $this->load($source);
        $dimensions = $this->dimensions($source);
        $logicalWidth = $dimensions['width']; $logicalHeight = $dimensions['height'];
        if (!in_array((new ImageFormatRegistry())->formatForMime($mime), $this->options->editableFormats, true)) $this->fail('This image cannot be edited.', 'image_format_unsupported', 415);
        $quality = $this->options->quality;
        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            if (!in_array($type, $this->options->actions, true)) $this->fail('The image action is disabled by configuration.', 'invalid_image_action', 422);
            if ($type === 'rotate') {
                $degrees = $this->integer($action, 'degrees') % 360;
                $radians = deg2rad($degrees);
                $width = max(1, (int) ceil(abs(imagesx($image) * cos($radians)) + abs(imagesy($image) * sin($radians)) - 0.000000001));
                $height = max(1, (int) ceil(abs(imagesx($image) * sin($radians)) + abs(imagesy($image) * cos($radians)) - 0.000000001));
                $this->checkSize($width, $height);
                $background = in_array($mime, ['image/png', 'image/webp', 'image/avif'], true) ? imagecolorallocatealpha($image, 0, 0, 0, 127) : 0;
                if ($background === false) $this->fail('Cannot prepare the image background.');
                $rotated = imagerotate($image, -$degrees, $background);
                if (!$rotated instanceof \GdImage) $this->fail('Cannot rotate the image.');
                $image = $rotated;
                unset($rotated);
                $logicalWidth = imagesx($image); $logicalHeight = imagesy($image);
            } elseif ($type === 'crop') {
                $width = $this->integer($action, 'width'); $height = $this->integer($action, 'height');
                $target = $this->canvas($width, $height, $mime);
                if (!imagecopy($target, $image, 0, 0, $this->integer($action, 'x'), $this->integer($action, 'y'), $width, $height)) $this->fail('Cannot crop the image.');
                $image = $target;
                unset($target);
                $logicalWidth = $width; $logicalHeight = $height;
            } elseif ($type === 'resize') {
                $resized = $this->resize($image, $mime, $this->integer($action, 'width'), $this->integer($action, 'height'), $logicalWidth, $logicalHeight);
                if ($resized !== $image) { $logicalWidth = imagesx($resized); $logicalHeight = imagesy($resized); }
                $image = $resized;
                unset($resized);
                $quality = $this->options->resizeQuality;
            } else {
                $this->fail('The image action is unsupported.', 'invalid_image_action', 422);
            }
        }
        $this->checkSize(imagesx($image), imagesy($image));
        $quality = $encodingQuality ?? $quality;
        // Encode once after the complete sequence with server-owned quality settings.
        if (in_array($mime, ['image/png', 'image/webp', 'image/avif'], true)) { imagealphablending($image, false); imagesavealpha($image, true); }
        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($image, $destination, $quality),
            'image/png' => imagepng($image, $destination),
            'image/gif' => imagegif($image, $destination),
            'image/webp' => imagewebp($image, $destination, $quality),
            'image/avif' => imageavif($image, $destination, $quality),
            'image/bmp' => imagebmp($image, $destination),
            default => false,
        };
        if (!$saved) $this->fail('Cannot encode the image.');
    }

    /** @return array{\GdImage,string} */
    private function load(string $source): array
    {
        $this->dimensions($source);
        $info = getimagesize($source);
        if (!is_array($info)) $this->fail('Cannot read the image.', 'invalid_image', 415);
        $mime = $info['mime'];
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source), 'image/png' => @imagecreatefrompng($source),
            'image/gif' => @imagecreatefromgif($source), 'image/bmp' => @imagecreatefrombmp($source),
            'image/webp' => @imagecreatefromwebp($source), 'image/avif' => @imagecreatefromavif($source), default => false,
        };
        if (!$image instanceof \GdImage) $this->fail('Cannot decode the image.', 'invalid_image', 415);
        return [ImageOrientation::apply($image, $source, $mime, $this->options->autoOrientation), $mime];
    }

    private function resize(\GdImage $image, string $mime, int $width, int $height, int $sourceWidth, int $sourceHeight): \GdImage
    {
        if ($width < 0 || $height < 0) $this->fail('Invalid resize dimensions.', 'invalid_image_dimensions', 422);
        $width = min($width ?: $sourceWidth, $this->options->maxWidth);
        $height = min($height ?: $sourceHeight, $this->options->maxHeight);
        $scale = min(1.0, $width / $sourceWidth, $height / $sourceHeight);
        if ($scale >= 1) return $image;
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $target = $this->canvas($targetWidth, $targetHeight, $mime);
        if (!imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) $this->fail('Cannot resize the image.');
        return $target;
    }

    private function canvas(int $width, int $height, string $mime): \GdImage
    {
        [$width, $height] = $this->checkSize($width, $height);
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) $this->fail('Cannot allocate the image.');
        if (in_array($mime, ['image/png', 'image/webp', 'image/avif'], true)) {
            imagealphablending($canvas, false); imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
            if ($transparent === false) $this->fail('Cannot allocate the image background.');
            imagefill($canvas, 0, 0, $transparent);
        }
        return $canvas;
    }

    /** @return array{positive-int,positive-int} */
    private function checkSize(int $width, int $height): array
    {
        if ($width < 1 || $height < 1 || $width > intdiv($this->maximumPixels, $height)) {
            $this->fail('The image exceeds the pixel limit.', 'image_too_large', 415);
        }
        return [$width, $height];
    }

    /** @param array<string,mixed> $action */
    private function integer(array $action, string $key): int
    {
        $value = $action[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]{1,10}$/D', $value) === 1)) {
            $this->fail('Invalid image action parameter.', 'invalid_image_actions', 422);
        }
        $number = (int) $value;
        if ($number < -2_147_483_648 || $number > 2_147_483_647) $this->fail('Image action parameter is out of range.', 'invalid_image_actions', 422);
        return $number;
    }

    private function fail(string $message, string $code = 'image_processing_failed', int $status = 500): never
    {
        throw new SoFinderException($message, $code, $status);
    }
}
