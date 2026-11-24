<?php

require_once __DIR__.'/../filament_resource_test_helpers.php';
require_once __DIR__.'/../../Api/api_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\Membranes\Pages\EditMembrane;
use App\Filament\Resources\Methods\Pages\EditMethod;
use App\Filament\Resources\SharedRelationManagers\FileRelationManager;
use App\Models\File;
use App\Models\Membrane;
use App\Models\Method;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Membranes, methods and publications keep their own files (images, COSMO
 * membrane definitions, exports) in the shared files tab.
 */

beforeEach(function () {
    seedFilamentResourcePermissions();
    $this->publicDiskRoot = useOwnPublicDiskForFilamentResourceTests();

    $this->actingAs(createFilamentResourceUser([
        PermissionEnums::MEMBRANE_METHOD_VIEW,
        PermissionEnums::MEMBRANE_METHOD_EDIT,
    ]));
});

afterEach(function () {
    removeOwnPublicDiskForFilamentResourceTests($this->publicDiskRoot);
});

function fileRmStoredFile(string $path, int $type = File::TYPE_IMAGE, ?string $hash = null): File
{
    Storage::disk('public')->put($path, 'file contents '.$path);

    return File::query()->create([
        'path' => $path,
        'name' => basename($path),
        'type' => $type,
        'storage' => 'public',
        'mime' => 'image/png',
        'hash' => $hash ?? md5($path),
    ]);
}

function fileRmAttach(Membrane|Method $owner, File $file): void
{
    $owner->files()->attach($file->id, ['model_type' => $owner::class]);
}

test('a method offers only images and stores an added image in its own folder', function () {
    $method = createApiMethod(['abbreviation' => 'PAMPA']);

    $component = Livewire::test(FileRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->mountTableAction('addNewFile')
        ->assertTableActionDataSet(['type' => null]);

    $typeOptions = $component->instance()->getMountedTableActionForm()->getComponent('type')->getOptions();
    expect($typeOptions)->toBe([File::TYPE_IMAGE => 'Image']);

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->callTableAction('addNewFile', data: [
            'type' => File::TYPE_IMAGE,
            'name' => 'pampa-scheme',
            'path' => UploadedFile::fake()->image('scheme.png'),
        ])
        ->assertHasNoTableActionErrors();

    $file = $method->files()->firstOrFail();

    expect($file)
        ->type->toBe(File::TYPE_IMAGE)
        ->name->toBe('pampa-scheme')
        ->storage->toBe('public')
        ->hash->not->toBeNull()
        ->path->toStartWith("uploads/methods/{$method->id}/image/")
        ->and(Storage::disk('public')->exists($file->path))->toBeTrue()
        ->and(DB::table('model_has_files')->where('file_id', $file->id)->value('model_type'))->toBe(Method::class);
});

test('a method refuses a COSMO membrane file', function () {
    $method = createApiMethod();

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->callTableAction('addNewFile', data: [
            'type' => File::TYPE_COSMO_MEMBRANE,
            'path' => UploadedFile::fake()->create('membrane.mic', 1),
        ])
        ->assertHasTableActionErrors(['type']);

    expect($method->files()->count())->toBe(0);
});

test('a COSMO file of a membrane is named after the membrane and gets no alternative name', function () {
    $membrane = createApiMembrane(['abbreviation' => 'DOPC']);

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->mountTableAction('addNewFile')
        ->setTableActionData(['type' => File::TYPE_COSMO_MEMBRANE])
        ->assertTableActionDataSet(['type' => File::TYPE_COSMO_MEMBRANE])
        ->setTableActionData(['path' => UploadedFile::fake()->create('anything.mic', 1)])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $file = $membrane->files()->firstOrFail();

    expect($file)
        ->type->toBe(File::TYPE_COSMO_MEMBRANE)
        ->name->toBeNull()
        ->path->toBe("uploads/membranes/{$membrane->id}/cosmo/DOPC_cosmo.inp");
});

test('an alternative file name may hold only letters, digits, dashes and underscores', function () {
    $membrane = createApiMembrane();

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->callTableAction('addNewFile', data: [
            'type' => File::TYPE_IMAGE,
            'name' => '../../etc/passwd',
            'path' => UploadedFile::fake()->image('scheme.png'),
        ])
        ->assertHasTableActionErrors(['name']);

    expect($membrane->files()->count())->toBe(0);
});

test('a file can be downloaded by its hash only', function () {
    $membrane = createApiMembrane();
    $withHash = fileRmStoredFile('uploads/membranes/hashed.png');
    fileRmAttach($membrane, $withHash);
    $withoutHash = fileRmStoredFile('uploads/membranes/unhashed.png');
    fileRmAttach($membrane, $withoutHash);
    $withoutHash->forceFill(['hash' => null])->saveQuietly();

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->assertCanSeeTableRecords([$withHash, $withoutHash])
        ->assertTableActionEnabled('download', $withHash)
        ->assertTableActionHasUrl('download', route('public.download', ['hash' => $withHash->hash]), $withHash)
        ->assertTableActionDisabled('download', $withoutHash);
});

test('deleting a file removes it from the disk unless another record uses the same path', function () {
    $membrane = createApiMembrane();
    $single = fileRmStoredFile('uploads/membranes/single.png');
    fileRmAttach($membrane, $single);
    $shared = fileRmStoredFile('uploads/membranes/shared.png');
    fileRmAttach($membrane, $shared);
    File::query()->create([
        'path' => 'uploads/membranes/shared.png',
        'type' => File::TYPE_IMAGE,
        'storage' => 'public',
        'mime' => 'image/png',
        'hash' => md5('another record of the shared file'),
    ]);

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->callTableAction('delete', $single)
        ->assertNotified('File deleted')
        ->callTableAction('delete', $shared);

    expect(File::query()->find($single->id))->toBeNull()
        ->and(Storage::disk('public')->exists('uploads/membranes/single.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('uploads/membranes/shared.png'))->toBeTrue();
});

test('files are deleted in bulk from the files tab', function () {
    $method = createApiMethod();
    $files = collect([fileRmStoredFile('uploads/methods/a.png'), fileRmStoredFile('uploads/methods/b.png')]);
    $files->each(fn (File $file) => fileRmAttach($method, $file));

    Livewire::test(FileRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->callTableBulkAction('delete', $files);

    expect(File::query()->whereKey($files->pluck('id'))->count())->toBe(0);
});
