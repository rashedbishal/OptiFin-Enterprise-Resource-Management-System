<?php

use App\Support\ApkManifestReader;

test('it reads the package, version name and build number from a real compiled manifest', function () {
    $xml = (string) file_get_contents(dirname(__DIR__).'/Fixtures/calling-app/AndroidManifest.xml');

    expect(ApkManifestReader::fromBinaryXml($xml))->toBe([
        'package' => 'com.optifin.calling',
        'version_name' => '1.4.0',
        'version_code' => 9,
    ]);
});

test('anything that is not compiled android xml yields null', function () {
    expect(ApkManifestReader::fromBinaryXml('<manifest versionName="9.9.9"/>'))->toBeNull()
        ->and(ApkManifestReader::fromBinaryXml(''))->toBeNull();
});
