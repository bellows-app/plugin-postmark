<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Dns;
use Bellows\PluginSdk\Facades\Domain;
use Bellows\PluginSdk\Facades\Entity;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Postmark extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected const MAILER = 'postmark';

    protected array $postmarkServer;

    protected array $sendingDomain;

    protected $verifyReturnPath = false;

    protected $verifyDKIM = false;

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function install(): ?InstallationResult
    {
        return InstallationResult::create();
    }

    public function deploy(): ?DeploymentResult
    {
        $this->http->createJsonClient(
            'https://api.postmarkapp.com/',
            fn (PendingRequest $request, array $credentials) => $request->withHeaders([
                'X-Postmark-Account-Token' => $credentials['token'],
            ]),
            new AddApiCredentialsPrompt(
                url: 'https://account.postmarkapp.com/api_tokens',
                helpText: 'Retrieve your <comment>Account</comment> token here.',
                credentials: ['token'],
                displayName: 'Postmark',
            ),
            fn (PendingRequest $request) => $request->get('servers', ['count' => 1, 'offset' => 0]),
        );

        $this->postmarkServer = $this->getServer();
        $this->sendingDomain = $this->getDomain();

        $this->updateDomainRecordsWithProvider();

        $messageStreamId = $this->getMessageStreamId();

        $fromEmail = Console::ask(
            'From email',
            "hello@{$this->sendingDomain['Name']}"
        );

        return DeploymentResult::create()
            ->environmentVariables([
                'MAIL_MAILER'                => self::MAILER,
                'MAIL_FROM_ADDRESS'          => $fromEmail,
                'POSTMARK_MESSAGE_STREAM_ID' => $messageStreamId,
                'POSTMARK_TOKEN'             => $this->postmarkServer['ApiTokens'][0],
            ])
            ->wrapUp(function () {
                if ($this->verifyReturnPath) {
                    $this->http->client()->put("domains/{$this->sendingDomain['ID']}/verifyReturnPath");
                }

                if ($this->verifyDKIM) {
                    $this->http->client()->put("domains/{$this->sendingDomain['ID']}/verifyDkim ");
                }
            });
    }

    public function requiredComposerPackages(): array
    {
        return [
            'symfony/postmark-mailer',
        ];
    }

    public function shouldDeploy(): bool
    {
        return Deployment::site()->env()->get('MAIL_MAILER') !== self::MAILER
            || !Deployment::site()->env()->hasAll('POSTMARK_MESSAGE_STREAM_ID', 'POSTMARK_TOKEN');
    }

    public function confirmDeploy(): bool
    {
        return Deployment::confirmChangeValueTo(
            Deployment::site()->env()->get('MAIL_MAILER'),
            self::MAILER,
            'Change mailer to Postmark'
        );
    }

    protected function getMessageStreamId()
    {
        $token = $this->postmarkServer['ApiTokens'][0];

        $streams = collect(
            Http::withHeaders([
                'X-Postmark-Server-Token' => $token,
            ])
                ->acceptJson()
                ->asJson()
                ->get('https://api.postmarkapp.com/message-streams')
                ->json()['MessageStreams']
        );

        $choices = $streams->mapWithKeys(
            fn ($s) => [$s['ID'] => "{$s['Name']} ({$s['Description']})"]
        )->toArray();

        if (count($choices) === 1) {
            return array_key_first($choices);
        }

        return Console::choice(
            'Which Postmark message stream',
            $choices,
            'outbound',
        );
    }

    protected function updateDomainRecordsWithProvider()
    {
        if (
            $this->sendingDomain['ReturnPathDomainVerified']
            && $this->sendingDomain['DKIMVerified']
        ) {
            // Nothing to do here, we good.
            return;
        }

        if (!Dns::available()) {
            Console::warn('Skipping DNS verification as no DNS provider is configured.');

            return;
        }

        if (!$this->sendingDomain['ReturnPathDomainVerified']) {
            Console::miniTask('Adding ReturnPath record to', Dns::providerName());

            Dns::addCNAMERecord(
                name: 'pm-bounces.' . Domain::getSubdomain($this->sendingDomain['Name']),
                value: $this->sendingDomain['ReturnPathDomainCNAMEValue'],
                ttl: 1800,
            );

            $this->verifyReturnPath = true;
        }

        if (!$this->sendingDomain['DKIMVerified']) {
            Console::miniTask('Adding DKIM record to ', Dns::providerName());

            Dns::addTXTRecord(
                name: Domain::getSubdomain($this->sendingDomain['DKIMPendingHost']),
                value: $this->sendingDomain['DKIMPendingTextValue'],
                ttl: 1800,
            );

            $this->verifyDKIM = true;
        }
    }

    protected function getServer()
    {
        $servers = collect(
            $this->http->client()->get('servers', [
                'count'  => 200,
                'offset' => 0,
            ])->json()['Servers']
        );

        return Entity::from($servers)
            ->selectFromExisting(
                'Choose a Postmark server',
                'Name',
                Project::appName(),
                'Create new server'
            )
            ->createNew('Create new Postmark server?', $this->createServer(...))
            ->prompt();
    }

    protected function getDomain()
    {
        $domains = collect(
            $this->http->client()->get('domains', [
                'count'  => 200,
                'offset' => 0,
            ])->json()['Domains']
        );

        $domainId = Entity::from($domains)
            ->selectFromExisting(
                'Choose a Postmark sender domain',
                'Name',
                fn ($domain) => Str::contains($domain['Name'], Project::domain()),
                'Create new domain'
            )
            ->createNew('Create new Postmark domain?', $this->createDomain(...))
            ->prompt()['ID'];

        return $this->http->client()->get("domains/{$domainId}")->json();
    }

    protected function createServer()
    {
        $name = Console::ask('Server name', Project::appName());

        $color = Console::choice(
            'Server color',
            [
                'Blue',
                'Green',
                'Grey',
                'Orange',
                'Purple',
                'Red',
                'Turquoise',
                'Yellow',
            ],
            'Blue'
        );

        return $this->http->client()->post('servers', [
            'Name'  => $name,
            'Color' => $color,
        ])->json();
    }

    protected function createDomain()
    {
        $name = Console::ask('Domain name', 'mail.' . Project::domain());

        return $this->http->client()->post('domains', ['Name' => $name])->json();
    }
}
