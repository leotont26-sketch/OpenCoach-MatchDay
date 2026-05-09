<?php

if (!function_exists('matchday_install_report_bootstrap')) {
    function matchday_install_report_bootstrap(): void
    {
        if (defined('DEMO_MODE') && DEMO_MODE === true) {
        return;
        }

        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;

        $config = matchday_install_report_config();
        if (empty($config['enabled']) || empty($config['endpoint']) || empty($config['api_key'])) {
            return;
        }

        $now = time();
        $lastReport = matchday_install_report_last_time();
        $minInterval = max(300, (int)($config['min_interval_seconds'] ?? 43200));
        if ($lastReport > 0 && ($now - $lastReport) < $minInterval) {
            return;
        }

        $payload = matchday_install_report_build_payload();
        $ok = matchday_install_report_send($config['endpoint'], $payload, $config['api_key']);
        if ($ok) {
            matchday_install_report_write_last_time($now);
        }
    }

    function matchday_install_report_config(): array
    {
        return [
            'enabled' => true,
            // Zentrale Empfangs-URL. Beispiel: https://deine-domain.de/matchday-monitor/api/report.php
            'endpoint' => 'https://tont-online.de/zentrale/api/report.php',
            // Gemeinsamer Schlüssel zwischen MatchDay-Installation und Zentrale.
            'api_key' => '1987091720150121201604272009080319870707',
            // Intervall zwischen Meldungen in Sekunden. Standard: 12 Stunden.
            'min_interval_seconds' => 43200,
            // Eigene MatchDay-Version für spätere Bug-Zuordnung.
            'matchday_version' => '1.0.1',
            // Netzwerk-Timeout für den Report.
            'timeout_seconds' => 4,
            'product' => 'opencoach',
            'module' => 'matchday',
        ];
    }

    function matchday_install_report_build_payload(): array
{
    $settings = function_exists('load_settings') ? load_settings() : [];
    $config = matchday_install_report_config();
    $installId = matchday_install_report_install_id();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $scriptDir = rtrim($scriptDir, '/.');
    $baseUrl = $host !== '' ? $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '') : '';
    $lastGenerated = trim((string)($settings['tournament_generated_at'] ?? ''));
    $matchesFile = __DIR__ . '/data/matches.json';

    return [
        'install_id' => $installId,
        'product' => trim((string)($config['product'] ?? 'opencoach')),
        'module' => trim((string)($config['module'] ?? 'matchday')),
        'domain' => $host,
        'base_url' => $baseUrl,
        'php_version' => PHP_VERSION,
        'matchday_version' => trim((string)($config['matchday_version'] ?? 'unbekannt')),
        'last_generated_at' => $lastGenerated,
        'matches_last_modified_at' => is_file($matchesFile) ? date('Y-m-d H:i:s', (int)@filemtime($matchesFile)) : '',
        'reported_at' => date('Y-m-d H:i:s'),
        'portal_title' => trim((string)($settings['portal_title'] ?? '')),
        'club_name' => trim((string)($settings['club_name'] ?? '')),
    ];
}

    function matchday_install_report_install_id(): string
    {
        $file = __DIR__ . '/data/install_id.txt';
        if (is_file($file)) {
            $id = trim((string)@file_get_contents($file));
            if ($id !== '') {
                return $id;
            }
        }

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $id = md5(uniqid((string)mt_rand(), true));
        }

        @file_put_contents($file, $id);
        return $id;
    }

    function matchday_install_report_last_time(): int
    {
        $file = __DIR__ . '/data/install_report_last.txt';
        if (!is_file($file)) {
            return 0;
        }
        return (int)trim((string)@file_get_contents($file));
    }

    function matchday_install_report_write_last_time(int $timestamp): void
    {
        $file = __DIR__ . '/data/install_report_last.txt';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, (string)$timestamp);
    }

    function matchday_install_report_send(string $endpoint, array $payload, string $apiKey): bool
    {
        $timeout = max(2, (int)(matchday_install_report_config()['timeout_seconds'] ?? 4));
        $json = json_encode([
            'api_key' => $apiKey,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($endpoint, false, $context);
        if ($result === false && empty($http_response_header)) {
            return false;
        }

        $statusLine = $http_response_header[0] ?? '';
        return preg_match('~\s2\d\d\s~', $statusLine) === 1;
    }
}
