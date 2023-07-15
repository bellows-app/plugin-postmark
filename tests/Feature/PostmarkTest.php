<?php

use Bellows\Plugins\Postmark;
use Bellows\PluginSdk\Facades\Project;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('can create a new server and domain', function () {
    Http::fake([
        'servers?count=200&offset=0' => Http::response([
            'Servers' => [],
        ]),
        'servers' => Http::response([
            'ID'        => 1,
            'Name'      => 'Test Server',
            'ApiTokens' => [
                'test-api-token',
            ],
        ]),
        'domains?count=200&offset=0' => Http::response([
            'Domains' => [],
        ]),
        'domains' => Http::response([
            'ID'                       => 1,
            'Name'                     => 'mail.bellowstest.com',
            'ReturnPathDomainVerified' => true,
            'DKIMVerified'             => true,
        ]),
        'domains/1' => Http::response([
            'ID'                       => 1,
            'Name'                     => 'mail.bellowstest.com',
            'ReturnPathDomainVerified' => true,
            'DKIMVerified'             => true,
        ]),
        'message-streams' => Http::response([
            'MessageStreams' => [
                [
                    'ID'          => 'transactional',
                    'Name'        => 'Transactional',
                    'Description' => 'Transactional emails',
                ],
                [
                    'ID'          => 'outbound',
                    'Name'        => 'Outbound',
                    'Description' => 'Outbound emails',
                ],
            ],
        ]),
    ]);

    $result = $this->plugin(Postmark::class)
        ->expectsQuestion('Server name', 'Test Server')
        ->expectsQuestion('Server color', 'Purple')
        ->expectsQuestion('Domain name', 'mail.bellowstest.com')
        ->expectsQuestion('Which Postmark message stream', 'outbound')
        ->expectsQuestion('From email', 'hello@mail.bellowstest.com')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'MAIL_MAILER'                => 'postmark',
        'MAIL_FROM_ADDRESS'          => 'hello@mail.bellowstest.com',
        'POSTMARK_MESSAGE_STREAM_ID' => 'outbound',
        'POSTMARK_TOKEN'             => 'test-api-token',
    ]);

    $this->assertRequestWasSent('POST', 'servers', [
        'Name'  => 'Test Server',
        'Color' => 'Purple',
    ]);

    $this->assertRequestWasSent('POST', 'domains', [
        'Name' => 'mail.bellowstest.com',
    ]);
});

it('can select an existing server and domain from the list', function () {
    Http::fake([
        'servers?count=200&offset=0' => Http::response([
            'Servers' => [
                [
                    'ID'        => 1,
                    'Name'      => Project::appName(),
                    'ApiTokens' => [
                        'test-api-token',
                    ],
                ],
            ],
        ]),
        'domains?count=200&offset=0' => Http::response([
            'Domains' => [
                [
                    'ID'                       => 1,
                    'Name'                     => 'mail.bellowstest.com',
                    'ReturnPathDomainVerified' => true,
                    'DKIMVerified'             => true,
                ],
            ],
        ]),
        'domains/1' => Http::response([
            'ID'                       => 1,
            'Name'                     => 'mail.bellowstest.com',
            'ReturnPathDomainVerified' => true,
            'DKIMVerified'             => true,
        ]),
        'message-streams' => Http::response([
            'MessageStreams' => [
                [
                    'ID'          => 'transactional',
                    'Name'        => 'Transactional',
                    'Description' => 'Transactional emails',
                ],
                [
                    'ID'          => 'outbound',
                    'Name'        => 'Outbound',
                    'Description' => 'Outbound emails',
                ],
            ],
        ]),
    ]);

    $result = $this->plugin(Postmark::class)
        ->expectsQuestion('Choose a Postmark server', Project::appName())
        ->expectsConfirmation('Create new Postmark domain?', 'no')
        ->expectsQuestion('Choose a Postmark sender domain', 'mail.bellowstest.com')
        ->expectsQuestion('Which Postmark message stream', 'outbound')
        ->expectsQuestion('From email', 'hello@mail.bellowstest.com')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'MAIL_MAILER'                => 'postmark',
        'MAIL_FROM_ADDRESS'          => 'hello@mail.bellowstest.com',
        'POSTMARK_MESSAGE_STREAM_ID' => 'outbound',
        'POSTMARK_TOKEN'             => 'test-api-token',
    ]);
});
