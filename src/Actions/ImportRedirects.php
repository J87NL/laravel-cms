<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Enums\RedirectSource;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Redirects\ImportResult;
use J87NL\Cms\Redirects\RedirectPaths;
use SplFileObject;
use Throwable;

class ImportRedirects
{
    /**
     * @var list<string>
     */
    private const array HEADER_NAMES = [
        'from',
        'from_path',
        'van',
    ];

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const int MAX_FROM_LENGTH = 255;

    private const int MAX_TO_LENGTH = 2048;

    public function __construct(
        private readonly SaveRedirect $saveRedirect,
        private readonly RedirectPaths $paths,
    ) {}

    public function handle(string $file): ImportResult
    {
        $result = new ImportResult;

        DB::beginTransaction();

        try {
            $this->importRows($result, $file);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($result->errors !== []) {
            DB::rollBack();

            $result->imported = 0;

            return $result;
        }

        DB::commit();

        return $result;
    }

    private function importRows(ImportResult $result, string $file): void
    {
        $csv = new SplFileObject($file);
        $delimiter = $this->delimiter($csv);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl($delimiter, '"', '');

        foreach ($csv as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $line = (int) $index + 1;
            $columns = array_values(array_map(fn (mixed $value): string => trim((string) $value), $row));

            if ($line === 1 && isset($columns[0])) {
                $columns[0] = trim($this->withoutByteOrderMark($columns[0]));
            }

            if ($line === 1 && in_array(strtolower($columns[0] ?? ''), self::HEADER_NAMES, true)) {
                continue;
            }

            $this->importRow($result, $line, $columns);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function importRow(ImportResult $result, int $line, array $columns): void
    {
        $from = $columns[0] ?? '';
        $to = $columns[1] ?? '';
        $status = (int) (($columns[2] ?? '') === '' ? 301 : $columns[2]);

        if ($from === '' || $to === '') {
            $result->fail($line, __('cms::redirects.import.missing_columns'));

            return;
        }

        $invalid = $this->invalidReason($from, $to);

        if (! is_null($invalid)) {
            $result->fail($line, $invalid);

            return;
        }

        if (! in_array($status, Redirect::STATUS_CODES, true)) {
            $result->fail($line, __('cms::redirects.import.invalid_status'));

            return;
        }

        $redirect = Redirect::query()
            ->where('from_path', $this->paths->from($from))
            ->first() ?? new Redirect;

        try {
            $this->saveRedirect->handle($redirect, $from, $to, $status, RedirectSource::Import);
        } catch (ValidationException $exception) {
            $result->fail($line, (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $result->imported++;
    }

    private function invalidReason(string $from, string $to): ?string
    {
        if (mb_strlen($from) > self::MAX_FROM_LENGTH) {
            return __('cms::redirects.import.from_too_long', [
                'max' => self::MAX_FROM_LENGTH,
            ]);
        }

        if (mb_strlen($to) > self::MAX_TO_LENGTH) {
            return __('cms::redirects.import.to_too_long', [
                'max' => self::MAX_TO_LENGTH,
            ]);
        }

        if (preg_match('#^(/|https?://)#i', $to) !== 1) {
            return __('cms::redirects.import.invalid_target');
        }

        return null;
    }

    private function withoutByteOrderMark(string $value): string
    {
        return str_starts_with($value, self::BYTE_ORDER_MARK) ? substr($value, strlen(self::BYTE_ORDER_MARK)) : $value;
    }

    private function delimiter(SplFileObject $csv): string
    {
        $firstLine = (string) $csv->fgets();
        $csv->rewind();

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }
}
