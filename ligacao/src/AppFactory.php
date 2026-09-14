<?php

declare(strict_types=1);

namespace App;

final class AppFactory
{
    private HttpClientInterface $http;
    private ?CallsGateway $calls = null;
    private ?RecordingProvider $recordings = null;
    private ?AnalysisService $analysis = null;
    private ?WebphoneService $webphone = null;

    public function __construct(private Config $config, ?HttpClientInterface $http = null, private string $accountId = '1', private ?string $storageRoot = null)
    {
        AccountRegistry::validateId($accountId);
        $this->storageRoot ??= dirname(__DIR__) . '/storage';
        $allowedHosts = preg_split(
            '/\s*,\s*/',
            $config->get('RECORDING_ALLOWED_HOST_SUFFIXES', 'api4com.com') ?: 'api4com.com',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: ['api4com.com'];
        $this->http = $http ?: new CurlHttpClient(
            $config->int('HTTP_CONNECT_TIMEOUT_SECONDS', 10, 1, 60),
            $config->int('HTTP_TIMEOUT_SECONDS', 30, 5, 300),
            dirname(__DIR__) . '/storage/tmp',
            $config->int('HTTP_MAX_REDIRECTS', 3, 0, 5),
            new RemoteUrlPolicy($allowedHosts)
        );
    }

    public function calls(): CallsGateway
    {
        if (!$this->calls instanceof CallsGateway) {
            $this->calls = new Api4ComClient($this->http, $this->config);
        }
        return $this->calls;
    }

    public function recordings(): RecordingProvider
    {
        if (!$this->recordings instanceof RecordingProvider) {
            $this->recordings = new RecordingService(
                $this->calls(),
                $this->http,
                $this->config->int('MAX_AUDIO_BYTES', 26214400, 1048576, 104857600)
            );
        }
        return $this->recordings;
    }

    public function http(): HttpClientInterface
    {
        return $this->http;
    }

    public function analysis(): AnalysisService
    {
        if (!$this->analysis instanceof AnalysisService) {
            $store = $this->analysisStore();
            $ai = new GeminiClient($this->http, $this->config);
            $this->analysis = new AnalysisService(
                $store,
                $this->recordings(),
                $ai,
                $this->calls()
            );
        }
        return $this->analysis;
    }

    public function analysisStore(): AnalysisStore
    {
        return new AnalysisStore($this->storageRoot . ($this->accountId === '1' ? '/analyses' : '/accounts/2/analyses'));
    }

    public function webphone(): WebphoneService
    {
        if (!$this->webphone instanceof WebphoneService) {
            $gateway = new Api4ComWebphoneClient($this->http, $this->config);
            $this->webphone = new WebphoneService(
                $gateway,
                $this->config->get('WEBPHONE_EXTENSION', '1000') ?: '1000'
            );
        }
        return $this->webphone;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function trash(): CallTrashStore
    {
        return new CallTrashStore($this->storageRoot . '/trash', $this->accountId);
    }

    public function extensionClient(): Api4ComWebphoneClient
    {
        return new Api4ComWebphoneClient($this->http, $this->config);
    }
}
