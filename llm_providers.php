<?php
/** Shared connection policy; no host application or database dependency. */
final class LLMProviders {
    const LABELS = ['openrouter' => 'OpenRouter', 'yandex' => 'Yandex Cloud', 'genrouter' => 'GenRouter'];

    public static function enabled(array $cfg, string $provider): bool {
        return isset(self::LABELS[$provider])
            && (int) ($cfg['LLM_ENABLED_' . strtoupper($provider)] ?? ($provider === 'genrouter' ? 0 : 1)) === 1;
    }

    public static function ready(array $cfg, string $provider): bool {
        return self::enabled($cfg, $provider) && !empty($cfg[strtoupper($provider) . '_API_KEY'])
            && ($provider !== 'yandex' || !empty($cfg['YANDEX_FOLDER_ID']));
    }

    public static function requireEnabled(array $cfg, string $provider): void {
        if (!self::enabled($cfg, $provider)) throw new RuntimeException($provider . ': провайдер отключён');
    }

    public static function proxyOptions(array $cfg, string $provider): array {
        $proxy = trim((string) ($cfg['LLM_PROXY'] ?? ''));
        if ($proxy === '' || (int) ($cfg['LLM_PROXY_' . strtoupper($provider)] ?? ($provider === 'openrouter' ? 1 : 0)) !== 1) return [];
        $options = [CURLOPT_PROXY => $proxy];
        $auth = trim((string) ($cfg['LLM_PROXY_AUTH'] ?? ''));
        if ($auth !== '') $options[CURLOPT_PROXYUSERPWD] = $auth;
        return $options;
    }
}
