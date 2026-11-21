<?php

namespace Modules\References\CrossRef;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\References\Models\Record;

class CrossRef
{
    protected string $baseUrl = '';

    public function __construct()
    {
        // From config, not env(): env() is empty once the configuration is
        // cached, and tests can point config at a fake host reliably.
        $this->baseUrl = rtrim((string) config('services.crossref.endpoint'), '/');

        if (! $this->baseUrl) {
            throw new Exception('CrossRef endpoint is not set (services.crossref.endpoint).');
        }
    }

    public function url()
    {
        return $this->baseUrl;
    }

    public function connected()
    {
        return $this->work('10.1093/database/baz078') !== null;
    }

    /**
     * Returns record by doi
     */
    public function work(
        string $doi,
    ): ?Record {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get("{$this->baseUrl}/works/$doi");

            $response = $this->processResponse($response);

            if (! $response) {
                return null;
            }

            $validator = Validator::make($response, [
                'status' => 'required|string',
                'message.DOI' => 'required|string',
            ]);

            if ($validator->fails() || $response['status'] != 'ok') {
                return null;
            }

            return Record::fromCrossRefResponse($response['message']);
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['DOI' => $doi]);

            return null;
        }
    }

    public function processResponse(\Illuminate\Http\Client\Response $response)
    {
        if ($response->successful()) {
            return $response->json();
        } elseif ($response->clientError()) {
            Log::error('Client error. Code:'.$response->status());

            return null;
        } elseif ($response->serverError()) {
            Log::error('Server error. Code:'.$response->status());

            return null;
        } else {
            Log::error('Unknown error. Code:'.$response->status());

            return null;
        }
    }
}
