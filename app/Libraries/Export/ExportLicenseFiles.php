<?php

namespace App\Libraries\Export;

use App\Support\PublicApiUrl;
use ZipArchive;

/**
 * README.txt and LICENSE.txt shipped in every MolMeDB export archive, so a
 * downloaded file keeps its license, citation and provenance (FAIR R1.1/R1.2)
 * after it leaves the site. Texts come from config/fair.php.
 */
class ExportLicenseFiles
{
    /**
     * Adds README.txt and LICENSE.txt after the data files (callers rely on the
     * data file staying at index 0, see ExportToFile::keepLastChanged()).
     */
    public static function addTo(ZipArchive $zip, string $contents): void
    {
        $zip->addFromString('README.txt', self::readme($contents));
        $zip->addFromString('LICENSE.txt', self::license());
    }

    public static function readme(string $contents): string
    {
        $lines = [
            config('fair.dataset_name'),
            str_repeat('=', mb_strlen((string) config('fair.dataset_name'))),
            '',
            'Contents: '.$contents,
            'Exported: '.now()->toDateString().' (MolMeDB '.config('fair.version').')',
            'Source: '.config('fair.frontend_url'),
            '',
            'License',
            '-------',
            'The data are dedicated to the public domain under '.config('fair.data_license.name').' ('.config('fair.data_license.url').').',
            'See LICENSE.txt. You may use them for any purpose without asking permission.',
            '',
            'How to cite',
            '-----------',
            'CC0 does not require attribution, but please cite MolMeDB when you use the data:',
            config('fair.citation.text'),
            '',
        ];

        foreach (config('fair.related_publications', []) as $publication) {
            $lines[] = 'Related: '.implode(', ', $publication['authors']).': '.$publication['title'].'. '
                .$publication['journal'].', '.$publication['year'].', '.$publication['url'];
        }

        return implode("\n", [
            ...$lines,
            '',
            'Each record lists its primary (and secondary) reference; please also cite the',
            'original sources of the data you rely on.',
            '',
            'More data',
            '---------',
            'REST API: '.PublicApiUrl::to('docs'),
            'RDF (SPARQL): '.config('fair.rdf.sparql_endpoint'),
            'RDF dump: '.config('fair.rdf.dump_url'),
            'Contact: '.config('fair.contact_email'),
            '',
        ]);
    }

    public static function license(): string
    {
        return implode("\n", [
            config('fair.dataset_name'),
            '',
            'To the extent possible under law, the authors of MolMeDB have waived all',
            'copyright and related or neighboring rights to the MolMeDB data under the',
            'Creative Commons CC0 1.0 Universal Public Domain Dedication.',
            '',
            'Summary: '.config('fair.data_license.url'),
            'Legal code: https://creativecommons.org/publicdomain/zero/1.0/legalcode',
            '',
        ]);
    }

    /**
     * HTTP Link header pointing a direct CSV download to its license and citation.
     */
    public static function linkHeader(): string
    {
        return implode(', ', [
            '<'.config('fair.data_license.url').'>; rel="license"',
            '<'.config('fair.citation.url').'>; rel="cite-as"',
            '<'.PublicApiUrl::to('about').'>; rel="describedby"; type="application/json"',
        ]);
    }
}
