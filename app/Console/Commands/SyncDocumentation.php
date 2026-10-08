<?php

namespace App\Console\Commands;

use App\Services\Documentation\DocumentationPublisher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publishes the documentation articles kept in resources/docs (those that
 * describe the code: the API, the MCP server, data upload, predictions) to
 * the documentation pages. Runs on every deployment; articles written in the
 * administration, or switched there to be edited by hand, are left alone.
 */
class SyncDocumentation extends Command
{
    protected $signature = 'docs:sync
        {--dry-run : Only render the articles and report what would change}
        {--directory= : Directory with the articles (default: resources/docs)}';

    protected $description = 'Publishes the documentation articles from resources/docs.';

    public function handle(DocumentationPublisher $publisher): int
    {
        try {
            $rows = $publisher->publishAll($this->option('directory') ?: null, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Article', 'Source', 'Result'], $rows);

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was saved.');
        }

        return self::SUCCESS;
    }
}
