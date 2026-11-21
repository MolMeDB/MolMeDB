<?php

require_once __DIR__.'/api_contract_seed.php';

use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Models\Dataset;
use App\Models\DownloadQueue;
use App\Models\FeedbackEmailVerification;
use App\Models\File;
use App\Models\Filesystem;
use App\Models\UploadQueue;
use App\Models\User;
use App\ValueObjects\UploadQueueConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Pins the responses of the internal laboratory upload and downloader
 * endpoints used by the frontend (see Tests\Support\ApiContract).
 */

const LAB_UPLOAD_CONTRACT_CSV = "SMILES,Gpen\nCCO,1.25\nCN1C=NC2=C1C(=O)N(C(=O)N2C)C,-2.10\n";

const LAB_UPLOAD_CONTRACT_GUEST_TOKEN = 'contract-guest-token-0123456789abcdef';

beforeEach(function () {
    prepareApiEndpointTestEnvironment();

    config()->set('services.turnstile.enabled', false);
    config()->set('services.europe_pmc.endpoint', 'https://europepmc.test');

    Http::fake([
        'https://europepmc.test/search*' => Http::response([
            'hitCount' => 1,
            'resultList' => [
                'result' => [[
                    'id' => '4001',
                    'source' => 'MED',
                    'title' => 'EuropePMC membrane paper',
                    'doi' => '10.1000/eupmc',
                    'authorString' => 'Alpha B',
                    'journalInfo' => [
                        'journal' => ['title' => 'Membrane Journal'],
                        'yearOfPublication' => 2024,
                    ],
                ]],
            ],
        ], 200),
    ]);
    Http::preventStrayRequests();

    Mail::fake();
    Notification::fake();
    Queue::fake();

    fakePublicStorageForTests();
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

/**
 * Upload queue record of a passive dataset with its CSV file on the public
 * disk, a log entry with payload and every frontend config value filled.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $attributes
 */
function createLabUploadContractRecord(?User $user, int $state, array $config = [], array $attributes = []): UploadQueue
{
    $world = seedApiContractWorld();
    $owner = $user ?? User::factory()->create(['name' => 'Dataset Owner', 'email' => 'owner@example.org']);

    $dataset = createApiDataset([
        'owner' => $owner,
        'type' => Dataset::TYPE_PASSIVE,
        'name' => 'Caffeine permeability',
        'membrane' => $world['membrane'],
        'method' => $world['method'],
        'publication' => $world['publication'],
    ]);

    $path = 'upload_queue/passive/contract.csv';
    Storage::disk('public')->put($path, LAB_UPLOAD_CONTRACT_CSV);

    $file = File::query()->create([
        'path' => $path,
        'name' => 'contract.csv',
        'type' => File::TYPE_UPLOAD_PASSIVE,
        'storage' => 'public',
        'mime' => 'text/csv',
        'hash' => md5(LAB_UPLOAD_CONTRACT_CSV),
    ]);

    $record = UploadQueue::query()->create([
        'type' => Dataset::TYPE_PASSIVE,
        'state' => $state,
        'file_id' => $file->id,
        'dataset_id' => $dataset->id,
        'user_id' => $user?->id,
        'config' => $config,
        ...$attributes,
    ]);

    $record->addStructuredLog(
        'Upload request created from frontend laboratory form.',
        UploadQueueLogContextEnums::INFO,
        UploadQueueLogTypeEnums::UPLOAD,
        $state,
        ['file_name' => 'contract.csv', 'file_size' => strlen(LAB_UPLOAD_CONTRACT_CSV)],
        $owner->id,
    );

    return $record->refresh();
}

/**
 * Config of a record that went through quick and detailed validation and
 * admin review, so the frontend representation has every value filled.
 *
 * @return array<string, mixed>
 */
function labUploadContractFullConfig(): array
{
    return [
        UploadQueueConfig::SEPARATOR => ',',
        UploadQueueConfig::SKIP_FIRST_ROW => 1,
        UploadQueueConfig::ATTRIBUTES => ['smiles', 'g_pen'],
        UploadQueueConfig::VALIDATED_ROWS => 2,
        UploadQueueConfig::QUICK_VALIDATION_OK => true,
        UploadQueueConfig::QUICK_VALIDATION_AT => '2026-01-01T10:00:00.000000Z',
        UploadQueueConfig::DETAILED_VALIDATION_OK => true,
        UploadQueueConfig::DETAILED_VALIDATION_AT => '2026-01-01T10:05:00.000000Z',
        UploadQueueConfig::ADMIN_REVIEW_APPROVED => true,
        UploadQueueConfig::ADMIN_REVIEW_APPROVED_AT => '2026-01-02T08:00:00.000000Z',
        UploadQueueConfig::ADMIN_REVIEW_REJECTED_AT => '2026-01-01T12:00:00.000000Z',
        UploadQueueConfig::ADMIN_REVIEW_REJECTED_REASON => 'Missing temperature column.',
        UploadQueueConfig::PROCESSING_PROGRESS => [
            'phase' => 'import',
            'mode' => 'chunked',
            'processed_rows' => 1,
            'created_rows' => 1,
            'skipped_rows' => 0,
            'next_line' => 3,
            'total_rows' => 2,
            'heartbeat_at' => '2026-01-02T08:10:00.000000Z',
        ],
    ];
}

/**
 * Configuration a frontend user saves after a successful quick validation.
 *
 * @return array<string, mixed>
 */
function labUploadContractConfiguredConfig(): array
{
    return [
        UploadQueueConfig::SEPARATOR => ',',
        UploadQueueConfig::SKIP_FIRST_ROW => 1,
        UploadQueueConfig::ATTRIBUTES => ['smiles', 'g_pen'],
        UploadQueueConfig::QUICK_VALIDATION_OK => true,
        UploadQueueConfig::QUICK_VALIDATION_AT => '2026-01-01T10:00:00.000000Z',
    ];
}

function labUploadContractUser(): User
{
    return User::factory()->create(['name' => 'Jane Uploader', 'email' => 'jane.uploader@example.org']);
}

/**
 * Finished download of the export disk with its ZIP archive in place.
 */
function createDownloaderContractDownload(): DownloadQueue
{
    $filesystem = Filesystem::where('type', Filesystem::TYPE_EXPORTS)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);

    $download = DownloadQueue::create([
        'uuid' => '0f4c3a52-8f1e-4b8e-9c55-6a7d2b1e9f00',
        'state' => DownloadQueue::STATE_DONE,
        'selection' => DownloadQueue::normalizeSelection(['structure_identifiers' => ['MM00040']]),
        'selection_hash' => DownloadQueue::hashSelection(['structure_identifiers' => ['MM00040']]),
        'progress' => [
            'processed' => 2,
            'total' => 2,
            'percent' => 100,
            'run_token' => 'contract-run-token',
            'updated_at' => '2026-01-01T10:00:00.000000Z',
        ],
        'expires_at' => now()->addDays(DownloadQueue::EXPIRATION_DAYS),
    ]);

    $archive = tempnam(sys_get_temp_dir(), 'contract-export');
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::OVERWRITE);
    $zip->addFromString('passive_interactions.csv', "structure;membrane;method\nMM00040;EggPC;PAMPA\n");
    $zip->close();

    $path = $download->storageDirectory().'/molmedb_export.zip';
    Storage::disk($filesystem->systemName)->put($path, (string) file_get_contents($archive));
    unlink($archive);

    $download->forceFill(['file_path' => $path])->save();

    return $download;
}

test('lab upload membranes endpoint keeps its contract', function () {
    seedApiContractWorld();

    $this->actingAs(labUploadContractUser())
        ->getJson(apiRoutePath('api/lab/upload/membranes').'?query=egg')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertApiContract('GET api/lab/upload/membranes');
});

test('lab upload methods endpoint keeps its contract', function () {
    seedApiContractWorld();

    $this->actingAs(labUploadContractUser())
        ->getJson(apiRoutePath('api/lab/upload/methods').'?query=pampa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertApiContract('GET api/lab/upload/methods');
});

test('lab upload publications endpoint keeps its contract', function () {
    seedApiContractWorld();

    $this->actingAs(labUploadContractUser())
        ->getJson(apiRoutePath('api/lab/upload/publications').'?query=caffeine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertApiContract('GET api/lab/upload/publications');
});

test('lab upload publication lookup endpoint keeps its contract', function () {
    seedApiContractWorld();

    // The local MED publication 14998573 and the Europe PMC hit 4001 are both returned.
    $this->actingAs(labUploadContractUser())
        ->getJson(apiRoutePath('api/lab/upload/publications/lookup').'?query=permeability')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertApiContract('GET api/lab/upload/publications/lookup');
});

test('lab upload store endpoint keeps its contract', function () {
    $world = seedApiContractWorld();

    $this->actingAs(labUploadContractUser())
        ->post(apiRoutePath('api/lab/upload'), [
            'dataset_type' => Dataset::TYPE_PASSIVE,
            'dataset_name' => 'Caffeine permeability',
            'comment' => 'Measured at 25 °C.',
            'method_id' => $world['method']->id,
            'membrane_id' => $world['membrane']->id,
            'publication_pmid' => '14998573',
            'publication_lookup_provider' => 'europe_pmc',
            'publication_lookup_source' => 'MED',
            'turnstile_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('interactions.csv', LAB_UPLOAD_CONTRACT_CSV),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.publication_id', $world['publication']->id)
        ->assertApiContract('POST api/lab/upload');
});

test('lab upload email verification request endpoint keeps its contract', function () {
    $this->postJson(apiRoutePath('api/lab/upload/email-verification'), [
        'email' => 'guest.uploader@example.org',
        'turnstile_token' => 'test-token',
    ])
        ->assertOk()
        ->assertApiContract('POST api/lab/upload/email-verification');

    expect(FeedbackEmailVerification::query()->where('email', 'guest.uploader@example.org')->exists())->toBeTrue();
});

test('lab upload email verification verify endpoint keeps its contract', function () {
    $user = labUploadContractUser();

    FeedbackEmailVerification::query()->create([
        'email' => $user->email,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(15),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
    ]);

    // The login regenerates the session, which the API only starts for
    // requests coming from the stateful frontend (laravel/sanctum).
    $this->withHeaders(['Referer' => 'http://localhost'])
        ->postJson(apiRoutePath('api/lab/upload/email-verification/verify'), [
            'email' => $user->email,
            'code' => '123456',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertApiContract('POST api/lab/upload/email-verification/verify');
});

test('lab upload my-uploads endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    createLabUploadContractRecord($user, UploadQueue::STATE_RUNNING, labUploadContractFullConfig());

    $this->actingAs($user)
        ->getJson(apiRoutePath('api/lab/upload/my-uploads'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertApiContract('GET api/lab/upload/my-uploads');
});

test('lab upload track endpoint keeps its contract', function () {
    createLabUploadContractRecord(null, UploadQueue::STATE_RUNNING, labUploadContractFullConfig(), [
        'guest_email' => 'guest.uploader@example.org',
        'guest_token' => LAB_UPLOAD_CONTRACT_GUEST_TOKEN,
    ]);

    $this->getJson(apiRoutePath('api/lab/upload/track/'.LAB_UPLOAD_CONTRACT_GUEST_TOKEN))
        ->assertOk()
        ->assertApiContract('GET api/lab/upload/track/{token}');
});

test('lab upload configure preview endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    $record = createLabUploadContractRecord($user, UploadQueue::STATE_UPLOADED);

    $this->actingAs($user)
        ->getJson(apiRoutePath("api/lab/upload/{$record->id}/configure/preview").'?separator=,&skip_first_row=1')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertApiContract('GET api/lab/upload/{record}/configure/preview');
});

test('lab upload configure validate endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    $record = createLabUploadContractRecord($user, UploadQueue::STATE_UPLOADED);

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ',',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'g_pen'],
        ])
        ->assertOk()
        ->assertJsonPath('data.config.quick_validation_ok', true)
        ->assertApiContract('POST api/lab/upload/{record}/configure/validate');
});

test('lab upload enqueue endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    $record = createLabUploadContractRecord($user, UploadQueue::STATE_CONFIGURED, labUploadContractConfiguredConfig());

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/enqueue"))
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_PENDING)
        ->assertApiContract('POST api/lab/upload/{record}/enqueue');
});

test('lab upload revert endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    $record = createLabUploadContractRecord($user, UploadQueue::STATE_PENDING, labUploadContractConfiguredConfig());

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/revert"))
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_CONFIGURED)
        ->assertApiContract('POST api/lab/upload/{record}/revert');
});

test('lab upload cancel endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    $record = createLabUploadContractRecord($user, UploadQueue::STATE_UPLOADED);

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/cancel"))
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_CANCELED)
        ->assertApiContract('POST api/lab/upload/{record}/cancel');
});

test('lab upload reupload endpoint keeps its contract', function () {
    $user = labUploadContractUser();
    $record = createLabUploadContractRecord($user, UploadQueue::STATE_ERROR, labUploadContractConfiguredConfig());

    $this->actingAs($user)
        ->post(apiRoutePath("api/lab/upload/{$record->id}/reupload"), [
            'file' => UploadedFile::fake()->createWithContent('fixed.csv', "SMILES,Gpen\nCCO,1.30\n"),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_UPLOADED)
        ->assertApiContract('POST api/lab/upload/{record}/reupload');
});

test('downloader verify endpoint keeps its contract', function () {
    $world = seedApiContractWorld();

    $this->postJson(apiRoutePath('api/downloader/verify'), [
        'membrane_ids' => [$world['membrane']->id],
        'method_ids' => [$world['method']->id],
        'protein_ids' => [$world['protein']->id],
        'structure_identifiers' => ['MM00040'],
    ])
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertApiContract('POST api/downloader/verify');
});

test('downloader store endpoint keeps its contract', function () {
    $world = seedApiContractWorld();

    $this->postJson(apiRoutePath('api/downloader'), [
        'membrane_ids' => [$world['membrane']->id],
        'structure_identifiers' => ['MM00040'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.reused', false)
        ->assertApiContract('POST api/downloader');
});

test('downloader show endpoint keeps its contract', function () {
    $download = createDownloaderContractDownload();

    $this->getJson(apiRoutePath("api/downloader/{$download->uuid}"))
        ->assertOk()
        ->assertJsonPath('data.state', 'done')
        ->assertApiContract('GET api/downloader/{download}');
});

test('downloader file endpoint keeps its contract', function () {
    $download = createDownloaderContractDownload();

    $this->get(apiRoutePath("api/downloader/{$download->uuid}/file"))
        ->assertOk()
        ->assertDownload('molmedb_export.zip')
        ->assertApiContract('GET api/downloader/{download}/file');
});
