<?php

namespace Modules\PredictionWorkers\Services;

use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\DTO\JobResult;
use Modules\PredictionWorkers\DTO\PredictionResultJson;
use Modules\PredictionWorkers\DTO\SoluteResult;
use Modules\PredictionWorkers\Exceptions\InvalidCosmoResultException;
use Modules\PredictionWorkers\Exceptions\UnreadableCosmoResultException;
use Modules\PredictionWorkers\Models\PredictionFile;

class CosmoXmlParser
{
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
            throw new UnreadableCosmoResultException("The stored result is an archive, not a COSMO XML file, {$where}");
        }

        if (trim($content) === '') {
            throw new InvalidCosmoResultException('The file is empty.');
        }

        return $this->parseString($content);
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
