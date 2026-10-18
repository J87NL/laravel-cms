<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\UploadMedia;
use J87NL\Cms\Media\ImageCleaner;
use J87NL\Cms\Models\MediaAsset;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @var list<array{0: int, 1: int, 2: int}>
 */
const GRID_COLORS = [
    [255, 0, 0],
    [0, 255, 0],
    [0, 0, 255],
    [255, 255, 0],
    [255, 0, 255],
    [0, 255, 255],
];

const CELL = 16;

function temporaryImagePath(string $extension): string
{
    return sprintf('%s/%s.%s', sys_get_temp_dir(), uniqid('cms-test-', true), $extension);
}

function exifSegment(string $entries, int $count): string
{
    $tiff = "II\x2A\x00\x08\x00\x00\x00".pack('v', $count).$entries."\x00\x00\x00\x00";
    $exif = "Exif\x00\x00".$tiff;

    return "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
}

function withExif(string $path, string $segment): void
{
    $jpeg = (string) file_get_contents($path);

    file_put_contents($path, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
}

function orientedGridJpeg(int $orientation): string
{
    $image = imagecreatetruecolor(3 * CELL, 2 * CELL);

    foreach (GRID_COLORS as $index => [$red, $green, $blue]) {
        $x = ($index % 3) * CELL;
        $y = intdiv($index, 3) * CELL;

        imagefilledrectangle($image, $x, $y, $x + CELL - 1, $y + CELL - 1, (int) imagecolorallocate($image, $red, $green, $blue));
    }

    $path = temporaryImagePath('jpg');
    imagejpeg($image, $path, 100);
    withExif($path, exifSegment("\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00", 1));

    return $path;
}

/**
 * @return list<list<int>>
 */
function colorGrid(string $path): array
{
    $image = imagecreatefromjpeg($path);
    $columns = intdiv(imagesx($image), CELL);
    $rows = intdiv(imagesy($image), CELL);
    $grid = [];

    for ($row = 0; $row < $rows; $row++) {
        for ($column = 0; $column < $columns; $column++) {
            $rgb = imagecolorat($image, $column * CELL + intdiv(CELL, 2), $row * CELL + intdiv(CELL, 2));
            $grid[$row][$column] = nearestColor([($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]);
        }
    }

    return $grid;
}

/**
 * @param  array{0: int, 1: int, 2: int}  $rgb
 */
function nearestColor(array $rgb): int
{
    $distances = array_map(
        fn (array $color): int => abs($color[0] - $rgb[0]) + abs($color[1] - $rgb[1]) + abs($color[2] - $rgb[2]),
        GRID_COLORS,
    );

    return (int) array_search(min($distances), $distances, true);
}

test('every exif orientation is applied before the metadata is dropped', function (int $orientation, array $expected): void {
    $cleaned = app(ImageCleaner::class)->clean(orientedGridJpeg($orientation), 'image/jpeg');

    expect(colorGrid($cleaned['path']))->toBe($expected);
})->with([
    'normal' => [1, [[0, 1, 2], [3, 4, 5]]],
    'mirrored' => [2, [[2, 1, 0], [5, 4, 3]]],
    'rotated 180' => [3, [[5, 4, 3], [2, 1, 0]]],
    'flipped' => [4, [[3, 4, 5], [0, 1, 2]]],
    'transposed' => [5, [[0, 3], [1, 4], [2, 5]]],
    'rotated 90 clockwise' => [6, [[3, 0], [4, 1], [5, 2]]],
    'transversed' => [7, [[5, 2], [4, 1], [3, 0]]],
    'rotated 90 counterclockwise' => [8, [[2, 5], [1, 4], [0, 3]]],
]);

test('an orientation is still applied when the exif data raises a warning', function (): void {
    $clean = temporaryImagePath('jpg');
    imagejpeg(imagecreatefromjpeg(orientedGridJpeg(1)), $clean, 100);

    withExif($clean, exifSegment(
        "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"
        ."\x0E\x01\x02\x00\xFF\xFF\x00\x00\xF0\xFF\x00\x00",
        2,
    ));

    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    exif_read_data($clean);
    restore_error_handler();

    expect($warnings)->not->toBe([]);

    $cleaned = app(ImageCleaner::class)->clean($clean, 'image/jpeg');

    expect(colorGrid($cleaned['path']))->toBe([[3, 0], [4, 1], [5, 2]]);
});

test('images above the pixel budget are rejected before decoding', function (): void {
    $header = pack('N', 20000).pack('N', 20000)."\x08\x02\x00\x00\x00";
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $path = temporaryImagePath('png');
    file_put_contents($path, "\x89PNG\r\n\x1a\n".$chunk('IHDR', $header).$chunk('IEND', ''));

    expect(fn () => app(ImageCleaner::class)->clean($path, 'image/png'))
        ->toThrow(ValidationException::class, __('cms::validation.image_too_large', ['megapixels' => 40]));
});

test('images that cannot be decoded are rejected', function (string $mimeType, Closure $make): void {
    $path = $make();

    expect(fn () => app(ImageCleaner::class)->clean($path, $mimeType))
        ->toThrow(ValidationException::class);
})->with([
    'truncated jpeg' => ['image/jpeg', function (): string {
        $path = temporaryImagePath('jpg');
        imagejpeg(imagecreatetruecolor(200, 200), $path);
        $jpeg = (string) file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, intdiv(strlen($jpeg), 3)));

        return $path;
    }],
    'animated webp' => ['image/webp', function (): string {
        $chunk = fn (string $type, string $data): string => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
        $canvas = "\x02\x00\x00\x00".substr(pack('V', 9), 0, 3).substr(pack('V', 9), 0, 3);
        $frame = str_repeat("\x00", 16).$chunk('VP8L', "\x2F\x09\x40\x02\x00\x07\x10\x11\x11\x88\x88\xFE\x07\x00");
        $body = 'WEBP'.$chunk('VP8X', $canvas).$chunk('ANIM', "\x00\x00\x00\x00\x00\x00").$chunk('ANMF', $frame).$chunk('ANMF', $frame);
        $path = temporaryImagePath('webp');
        file_put_contents($path, 'RIFF'.pack('V', strlen($body)).$body);

        return $path;
    }],
    'animated gif' => ['image/gif', function (): string {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
        $path = temporaryImagePath('gif');
        file_put_contents($path, "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF".$frame.$frame."\x3B");

        return $path;
    }],
]);

test('a still gif is re-encoded', function (): void {
    $path = temporaryImagePath('gif');
    imagegif(imagecreatetruecolor(40, 30), $path);

    $cleaned = app(ImageCleaner::class)->clean($path, 'image/gif');

    expect($cleaned['path'])->not->toBe($path)
        ->and($cleaned['width'])->toBe(40)
        ->and(getimagesize($cleaned['path'])['mime'] ?? null)->toBe('image/gif');
});

test('a failed upload leaves no temporary file and no row', function (): void {
    $before = glob(sprintf('%s/cms-image-*', sys_get_temp_dir())) ?: [];

    expect(fn () => app(UploadMedia::class)->handle(UploadedFile::fake()->image('photo.jpg', 300, 200), []))
        ->toThrow(Exception::class);

    expect(glob(sprintf('%s/cms-image-*', sys_get_temp_dir())) ?: [])->toBe($before)
        ->and(MediaAsset::query()
            ->count())->toBe(0);
});
