<?php

namespace Modules\PredictionWorkers\Services;

use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\DTO\JobResult;
use Modules\PredictionWorkers\DTO\PredictionResultJson;
use Modules\PredictionWorkers\DTO\SoluteResult;
use Modules\PredictionWorkers\Exceptions\InvalidCosmoResultException;
use Modules\PredictionWorkers\Exceptions\UnreadableCosmoResultException;
use Modules\PredictionWorkers\Models\PredictionFile;
use ZipArchive;

class CosmoXmlParser
{
    /**
     * The largest XML file unpacked from a result archive.
     */
    public const MAX_ARCHIVED_XML_BYTES = 32 * 1024 * 1024;

    /**
     * Not an archive, an inconsistent one, an unexpected end and (35, named
     * ER_TRUNCATED_ZIP by newer versions of libzip only) a truncated one.
     *
     * @var array<int, int>
     */
    private const ERRORS_OF_THE_ARCHIVE_ITSELF = [ZipArchive::ER_NOZIP, ZipArchive::ER_INCONS, ZipArchive::ER_EOF, 35];

    /**
     * @throws UnreadableCosmoResultException when the file could not be read as a whole
     * @throws InvalidCosmoResultException when the file was read and is not a COSMO result
     */
    public function parse(PredictionFile $file): PredictionResultJson
    {
        $where = "on disk [{$file->storage}] path [{$file->path}]";
        $disk = Storage::disk($file->storage);

        if (! $disk->exists($file->path)) {
            throw new UnreadableCosmoResultException("COSMO XML file not found {$where}");
        }

        $content = $disk->get($file->path);

        if ($content === null) {
            throw new UnreadableCosmoResultException("Unable to read COSMO XML file {$where}");
        }

        // A read the storage gave up half way comes back as a shorter (or
        // empty) text, not as an error: only a file read to its full size is
        // judged by its content.
        $size = $disk->size($file->path);

        if (strlen($content) !== $size) {
            throw new UnreadableCosmoResultException(
                'COSMO XML file was read only in part ('.strlen($content)." of {$size} bytes) {$where}"
            );
        }

        // Results were also stored as the downloaded archive with the XML inside.
        if (str_starts_with($content, "PK\x03\x04")) {
            try {
                $content = $this->xmlFromArchive($content, strict: true)
                    ?? throw new InvalidCosmoResultException('The stored archive holds no COSMO XML file.');
            } catch (UnreadableCosmoResultException $exception) {
                throw new UnreadableCosmoResultException("{$exception->getMessage()} {$where}", previous: $exception);
            }
        }

        if (trim($content) === '') {
            throw new InvalidCosmoResultException('The file is empty.');
        }

        return $this->parseString($content);
    }

    /**
     * The COSMO XML packed in a result archive: the remote service zips the
     * whole calculation directory. Null when the content is no archive or
     * none of its XML files is a COSMO result.
     *
     * What keeps this server from unpacking an archive (no room for the
     * temporary file, an archive it cannot open for a reason of its own) says
     * nothing about the result. With $strict that is told apart by an
     * exception; without it the answer is null as well.
     *
     * @throws UnreadableCosmoResultException with $strict, when the archive could not be unpacked here
     * @throws InvalidCosmoResultException with $strict, when the result in the archive is too large to be one
     */
    public function xmlFromArchive(string $contents, bool $strict = false): ?string
    {
        try {
            return $this->unpackedXml($contents);
        } catch (UnreadableCosmoResultException|InvalidCosmoResultException $exception) {
            return $strict ? throw $exception : null;
        }
    }

    /**
     * @throws UnreadableCosmoResultException
     * @throws InvalidCosmoResultException
     */
    private function unpackedXml(string $contents): ?string
    {
        if (! str_starts_with($contents, 'PK')) {
            return null;
        }

        if (! class_exists(ZipArchive::class)) {
            throw new UnreadableCosmoResultException('The stored result is an archive and PHP cannot open archives here,');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'prediction-result-');

        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) !== strlen($contents)) {
            if ($temporaryPath !== false && file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw new UnreadableCosmoResultException('The stored archive could not be copied to a temporary file to unpack it,');
        }

        $archive = new ZipArchive;
        $isOpen = false;

        try {
            $opened = $archive->open($temporaryPath, ZipArchive::RDONLY);

            if ($opened !== true) {
                // Only these say the content itself is no (sound) archive.
                return in_array($opened, self::ERRORS_OF_THE_ARCHIVE_ITSELF, true)
                    ? null
                    : throw new UnreadableCosmoResultException("The stored archive could not be opened (zip error {$opened}),");
            }

            $isOpen = true;
            $tooLarge = null;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $entryName = is_array($entry) ? (string) ($entry['name'] ?? '') : '';

                if (! str_ends_with(strtolower($entryName), '.xml')) {
                    continue;
                }

                // The size is the one the archive declares: a file that would
                // unpack into more is not a result and is not unpacked.
                if ((int) ($entry['size'] ?? 0) > self::MAX_ARCHIVED_XML_BYTES) {
                    $tooLarge ??= $entryName;

                    continue;
                }

                $xml = $archive->getFromIndex($index, self::MAX_ARCHIVED_XML_BYTES);

                if ($xml === false) {
                    throw new UnreadableCosmoResultException("[{$entryName}] could not be unpacked from the stored archive,");
                }

                if (str_contains($xml, '<micoutput')) {
                    return $xml;
                }
            }

            if ($tooLarge !== null) {
                throw new InvalidCosmoResultException(
                    "The XML file [{$tooLarge}] in the stored archive is larger than a COSMO result (".intdiv(self::MAX_ARCHIVED_XML_BYTES, 1024 * 1024).' MB).'
                );
            }

            return null;
        } finally {
            if ($isOpen) {
                $archive->close();
            }

            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @throws InvalidCosmoResultException when the content is not a COSMO result
     */
    public function parseString(string $content): PredictionResultJson
    {
        $collectedBefore = libxml_use_internal_errors(true);
        $errorsBefore = count(libxml_get_errors());

        try {
            $xml = simplexml_load_string($content);
            $xmlError = array_slice(libxml_get_errors(), $errorsBefore)[0] ?? null;
        } finally {
            // Errors a caller collects for itself are left to it.
            if (! $collectedBefore) {
                libxml_clear_errors();
            }

            libxml_use_internal_errors($collectedBefore);
        }

        if ($xml === false) {
            throw new InvalidCosmoResultException(
                'The file is not well-formed XML'.($xmlError ? ' (line '.$xmlError->line.': '.trim($xmlError->message).')' : '').'.'
            );
        }

        if ($xml->getName() !== 'micoutput') {
            throw new InvalidCosmoResultException('Invalid root element. Expected <micoutput>.');
        }

        if (! isset($xml->joblist)) {
            throw new InvalidCosmoResultException('Missing <joblist> element.');
        }

        $jobs = [];

        foreach ($xml->joblist->job as $jobNode) {

            $temperatureK = (float) trim((string) $jobNode->temperature);
            $temperatureC = $temperatureK > 200
                ? $temperatureK - 273.15
                : $temperatureK;

            $layerPositions = $this->parseFloatList(
                (string) $jobNode->layerposition,
                roundTo: 1
            );

            $solutes = [];

            if (isset($jobNode->solutelist->solute)) {

                foreach ($jobNode->solutelist->solute as $soluteNode) {

                    $energyValues = $this->parseEnergyDistribution(
                        (string) $soluteNode->distribution,
                        $temperatureK
                    );

                    $solutes[] = new SoluteResult(
                        meanPosition: (float) $soluteNode->meanposition,
                        logK: (float) $soluteNode->logP_micelle_water,
                        logPerm: isset($soluteNode->logPerm_membrane_cm_s)
                            ? (float) $soluteNode->logPerm_membrane_cm_s
                            : null,
                        energyValues: $energyValues
                    );
                }
            }

            $jobs[] = new JobResult(
                jobNumber: trim((string) $jobNode->jobnumber),
                symmetry: trim((string) $jobNode->symmetry),
                layerCount: (int) $jobNode->nrlayer,
                layerPositions: $layerPositions,
                temperature: $temperatureC,
                solutes: $solutes
            );
        }

        return new PredictionResultJson($jobs);
    }

    private function parseFloatList(string $input, ?int $roundTo = null): array
    {
        $values = preg_split('/\s+/', trim($input));

        return array_map(function ($v) use ($roundTo) {
            $float = (float) $v;

            return $roundTo !== null ? round($float, $roundTo) : $float;
        }, array_filter($values));
    }

    private function parseEnergyDistribution(string $distribution, float $temperatureK): array
    {
        $values = preg_split('/\s+/', trim($distribution));

        $energies = [];

        foreach ($values as $v) {

            if (! is_numeric($v) || (float) $v == 0.0) {
                return [];
            }

            $probability = (float) $v;

            // ΔG = -RT ln(p) / 4185
            $deltaG = -8.314 * $temperatureK * log($probability) / 4185;

            $energies[] = $deltaG;
        }

        if (! empty($energies)) {
            $offset = end($energies);
            $energies = array_map(
                fn ($e) => round($e - $offset, 2),
                $energies
            );
        }

        return $energies;
    }
}
