<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

use IndianOpinion\ReadAloud\Exception\ProviderConfigException;

/**
 * Provider factories, extended through the read_aloud_providers filter.
 */
final class ProviderRegistry
{
    /**
     * @return array<string, callable(array<string, mixed>): SpeechProvider>
     */
    public function factories(): array
    {
        $builtIn = [
            'http' => static fn (array $settings): SpeechProvider => new HttpProvider($settings),
            'silent' => static fn (array $settings): SpeechProvider => new SilentProvider(),
        ];
        $factories = apply_filters('read_aloud_providers', $builtIn);
        if (!is_array($factories)) {
            return $builtIn;
        }
        $out = [];
        foreach ($factories as $id => $factory) {
            if (is_string($id) && preg_match('/^[a-z0-9-]{1,32}$/', $id) === 1 && is_callable($factory)) {
                $out[$id] = $factory;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $settings
     * @throws ProviderConfigException
     */
    public function make(string $id, array $settings): SpeechProvider
    {
        $factories = $this->factories();
        if (!isset($factories[$id])) {
            throw new ProviderConfigException($id === '' ? 'No provider is configured.' : 'Unknown provider: ' . $id);
        }
        $provider = ($factories[$id])($settings);
        if (!$provider instanceof SpeechProvider) {
            throw new ProviderConfigException('The provider factory for ' . $id . ' did not return a SpeechProvider.');
        }

        return $provider;
    }

    /** @return array<string, string> id => label */
    public function labels(): array
    {
        $out = [];
        foreach (array_keys($this->factories()) as $id) {
            try {
                $out[$id] = $this->make($id, [])->label();
            } catch (\Throwable $e) {
                $out[$id] = $id;
            }
        }

        return $out;
    }
}
