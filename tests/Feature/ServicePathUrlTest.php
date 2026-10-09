<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

function makePathRoutedService(array $services, bool $pathRouting = true): Service
{
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'compose_parsing_version' => '5',
        'docker_compose_raw' => Yaml::dump(['x-coolify-path-routing' => $pathRouting, 'services' => $services]),
    ]);
}

it('keeps the public origin when parsing and saving a path-routed service', function (string $scheme) {
    $service = makePathRoutedService([
        'web' => ['image' => 'nginx:alpine', 'environment' => ['SERVICE_URL_APP_8000' => '/']],
        'livestream' => ['image' => 'nginx:alpine', 'environment' => ['SERVICE_URL_APP_8080' => '/livestream']],
    ]);
    foreach (['web' => '', 'livestream' => '/livestream'] as $name => $path) {
        ServiceApplication::create([
            'name' => $name,
            'service_id' => $service->id,
            'image' => 'nginx:alpine',
            'fqdn' => $scheme.'://analytics.example.com'.$path,
        ]);
    }

    $service->parse();

    expect($service->environment_variables()->where('key', 'SERVICE_URL_APP')->first()->value)
        ->toBe($scheme.'://analytics.example.com');
    expect($service->environment_variables()->where('key', 'SERVICE_URL_APP_8080')->first()->value)
        ->toBe($scheme.'://analytics.example.com:8080/livestream');
    expect($service->environment_variables()->where('key', 'SERVICE_FQDN_APP')->first()->value)
        ->toBe('analytics.example.com');

    $livestream = $service->applications()->where('name', 'livestream')->first();
    $livestream->fqdn = $scheme.'://renamed.example.com/livestream';
    $livestream->save();
    updateCompose($livestream);

    expect($service->environment_variables()->where('key', 'SERVICE_URL_APP')->first()->value)
        ->toBe($scheme.'://renamed.example.com');
    expect($service->environment_variables()->where('key', 'SERVICE_URL_APP_8080')->first()->value)
        ->toBe($scheme.'://renamed.example.com:8080/livestream');
})->with(['http', 'https']);

it('routes multiple paths without stripping them and preserves a later user override', function () {
    $service = makePathRoutedService([
        'capture' => [
            'image' => 'nginx:alpine',
            'x-coolify-strip-prefix' => false,
            'environment' => ['SERVICE_URL_APP_3000' => '/e,/batch,/capture'],
        ],
    ]);

    $service->parse();

    $capture = $service->applications()->where('name', 'capture')->first();
    $domains = explode(',', $capture->fqdn);
    expect(array_map(fn ($domain) => parse_url($domain, PHP_URL_PATH), $domains))->toBe(['/e', '/batch', '/capture']);
    $host = parse_url($domains[0], PHP_URL_HOST);
    expect(array_map(fn ($domain) => parse_url($domain, PHP_URL_HOST), $domains))->toBe([$host, $host, $host]);
    expect((bool) $capture->isStripprefixEnabled())->toBeFalse();

    $capture->is_stripprefix_enabled = true;
    $capture->save();
    $service->parse();

    expect((bool) $capture->fresh()->isStripprefixEnabled())->toBeTrue();
    expect(explode(',', $capture->fresh()->fqdn))->toBe($domains);
});

it('keeps a single application base path when shared path routing is not enabled', function () {
    $service = makePathRoutedService([
        'web' => ['image' => 'nginx:alpine', 'environment' => ['SERVICE_URL_APP_8080' => '/reader']],
    ], pathRouting: false);
    ServiceApplication::create([
        'name' => 'web',
        'service_id' => $service->id,
        'image' => 'nginx:alpine',
        'fqdn' => 'https://reader.example.com/reader',
    ]);

    $service->parse();

    expect($service->environment_variables()->where('key', 'SERVICE_URL_APP')->first()->value)
        ->toBe('https://reader.example.com/reader');
    expect($service->environment_variables()->where('key', 'SERVICE_URL_APP_8080')->first()->value)
        ->toBe('https://reader.example.com:8080/reader');
});
