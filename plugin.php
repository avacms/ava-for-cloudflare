<?php

declare(strict_types=1);

/**
 * Ava for Cloudflare® Plugin
 *
 * Automatically purge Cloudflare cache when content is rebuilt.
 * 
 * Features:
 * - Automatic cache purge on content rebuild
 * - CLI commands for manual purge and status check
 *
 * Configuration in ava.php:
 * 
 *   'cloudflare' => [
 *       'enabled'  => true,
 *       'zone_id'  => 'your-zone-id',
 *       'api_token' => 'your-api-token',  // Requires cache_purge:edit permission
 *   ],
 *
 * Cloudflare® is a registered trademark of Cloudflare, Inc.
 * This plugin is not affiliated with or endorsed by Cloudflare, Inc.
 *
 * @package Ava\Plugins\Cloudflare
 */

use Ava\Application;
use Ava\Plugins\Hooks;

return [
    'name' => 'Ava for Cloudflare®',
    'version' => '1.1.0',
    'description' => 'Automatically purge Cloudflare cache on content rebuild',
    'author' => 'Ava CMS',

    'boot' => function (Application $app) {
        // Load configuration
        $config = array_merge([
            'enabled'   => false,
            'zone_id'   => '',
            'api_token' => '',
        ], $app->config('cloudflare', []));

        // Skip if not enabled or missing required config
        if (!$config['enabled']) {
            return;
        }

        $isConfigured = !empty($config['zone_id']) && !empty($config['api_token']);

        /**
         * Purge entire Cloudflare cache zone.
         * 
         * @return array{success: bool, message: string, details?: array}
         */
        $purgeCache = function () use ($config): array {
            if (empty($config['zone_id']) || empty($config['api_token'])) {
                return [
                    'success' => false,
                    'message' => 'Cloudflare zone_id and api_token are required',
                ];
            }

            $url = "https://api.cloudflare.com/client/v4/zones/{$config['zone_id']}/purge_cache";
            
            $payload = json_encode(['purge_everything' => true]);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $config['api_token'],
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return [
                    'success' => false,
                    'message' => 'cURL error: ' . $curlError,
                ];
            }

            $data = json_decode($response, true);
            
            if ($httpCode === 200 && ($data['success'] ?? false)) {
                return [
                    'success' => true,
                    'message' => 'Cloudflare cache purged successfully',
                    'details' => $data,
                ];
            }

            // Extract error message from Cloudflare response
            $errors = $data['errors'] ?? [];
            $errorMessage = !empty($errors) 
                ? implode(', ', array_map(fn($e) => $e['message'] ?? 'Unknown error', $errors))
                : 'Unknown error (HTTP ' . $httpCode . ')';

            return [
                'success' => false,
                'message' => 'Cloudflare API error: ' . $errorMessage,
                'details' => $data,
            ];
        };

        // Hook into content rebuild to automatically purge cache
        if ($isConfigured) {
            Hooks::addAction('indexer.rebuild', function () use ($purgeCache, $app) {
                $result = $purgeCache();
                
                // Log the result
                $storagePath = $app->configPath('storage');
                $logFile = $storagePath . '/logs/cloudflare.log';
                
                $logEntry = sprintf(
                    "[%s] %s: %s\n",
                    date('Y-m-d H:i:s'),
                    $result['success'] ? 'SUCCESS' : 'ERROR',
                    $result['message']
                );
                
                // Ensure logs directory exists
                $logsDir = dirname($logFile);
                if (!is_dir($logsDir)) {
                    mkdir($logsDir, 0755, true);
                }
                
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            });
        }
    },

    /*
    |───────────────────────────────────────────────────────────────────────────
    | CLI COMMANDS
    |───────────────────────────────────────────────────────────────────────────
    */

    'commands' => [
        [
            'name'        => 'cloudflare:status',
            'description' => 'Show Cloudflare integration status',
            'handler'     => function (array $args, $output, Application $app) {
                $config = array_merge([
                    'enabled'   => false,
                    'zone_id'   => '',
                    'api_token' => '',
                ], $app->config('cloudflare', []));

                $output->header('Cloudflare Integration Status');
                $output->writeln('');

                // Status table
                $rows = [
                    ['Enabled', $config['enabled'] ? 'Yes' : 'No'],
                    ['Zone ID', !empty($config['zone_id']) ? substr($config['zone_id'], 0, 8) . '...' : '(not set)'],
                    ['API Token', !empty($config['api_token']) ? '••••' . substr($config['api_token'], -4) : '(not set)'],
                ];

                $output->table(['Setting', 'Value'], $rows);

                $isConfigured = $config['enabled'] && !empty($config['zone_id']) && !empty($config['api_token']);
                
                $output->writeln('');
                if ($isConfigured) {
                    $output->success('✓ Cloudflare integration is active. Cache will be purged on rebuild.');
                } else {
                    $output->warning('⚠ Cloudflare integration is not fully configured.');
                    $output->writeln('');
                    $output->info('Add to app/config/ava.php:');
                    $output->writeln('');
                    $output->writeln("  'cloudflare' => [");
                    $output->writeln("      'enabled'   => true,");
                    $output->writeln("      'zone_id'   => 'your-zone-id',");
                    $output->writeln("      'api_token' => 'your-api-token',");
                    $output->writeln("  ],");
                }

                return 0;
            },
        ],
        [
            'name'        => 'cloudflare:purge',
            'description' => 'Purge all Cloudflare cached content',
            'handler'     => function (array $args, $output, Application $app) {
                $config = array_merge([
                    'enabled'   => false,
                    'zone_id'   => '',
                    'api_token' => '',
                ], $app->config('cloudflare', []));

                $output->header('Cloudflare Cache Purge');
                $output->writeln('');

                if (!$config['enabled']) {
                    $output->error('Cloudflare integration is not enabled.');
                    $output->info("Set 'cloudflare.enabled' => true in ava.php");
                    return 1;
                }

                if (empty($config['zone_id']) || empty($config['api_token'])) {
                    $output->error('Cloudflare zone_id and api_token are required.');
                    $output->info('Run ./ava cloudflare:status for configuration help.');
                    return 1;
                }

                $output->info('Purging Cloudflare cache...');

                // Make API request
                $url = "https://api.cloudflare.com/client/v4/zones/{$config['zone_id']}/purge_cache";
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['purge_everything' => true]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $config['api_token'],
                        'Content-Type: application/json',
                    ],
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    $output->error('cURL error: ' . $curlError);
                    return 1;
                }

                $data = json_decode($response, true);

                if ($httpCode === 200 && ($data['success'] ?? false)) {
                    $output->success('✓ Cloudflare cache purged successfully');
                    return 0;
                }

                // Extract error message
                $errors = $data['errors'] ?? [];
                $errorMessage = !empty($errors) 
                    ? implode(', ', array_map(fn($e) => $e['message'] ?? 'Unknown error', $errors))
                    : 'Unknown error (HTTP ' . $httpCode . ')';

                $output->error('Cloudflare API error: ' . $errorMessage);
                return 1;
            },
        ],
    ],
];
