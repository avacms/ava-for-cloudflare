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
 * - Admin page for configuration status and manual purging
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
use Ava\Http\Request;
use Ava\Http\Response;
use Ava\Plugins\Hooks;

return [
    'name' => 'Ava for Cloudflare®',
    'version' => '1.0.0',
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

        // Register admin page
        Hooks::addFilter('admin.register_pages', function ($pages) use ($config, $isConfigured, $purgeCache, $app) {
            $pages['cloudflare'] = [
                'label'   => 'Cloudflare',
                'icon'    => 'cloud',
                'section' => 'Plugins',
                'handler' => function (Request $request, Application $app, $controller) use ($config, $isConfigured, $purgeCache) {
                    $message = null;
                    $messageType = null;

                    // Handle manual purge request
                    if ($request->isMethod('POST') && $request->post('action') === 'purge') {
                        // Verify CSRF token
                        $token = $request->post('_token', '');
                        if (!$controller->verifyCsrfToken($token)) {
                            $message = 'Invalid security token. Please try again.';
                            $messageType = 'error';
                        } elseif (!$isConfigured) {
                            $message = 'Cloudflare is not configured. Please add zone_id and api_token to your configuration.';
                            $messageType = 'error';
                        } else {
                            $result = $purgeCache();
                            $message = $result['message'];
                            $messageType = $result['success'] ? 'success' : 'error';
                        }
                    }

                    // Build status display
                    $zoneIdDisplay = !empty($config['zone_id']) 
                        ? substr($config['zone_id'], 0, 8) . '...' 
                        : '<span class="text-red-500">Not configured</span>';
                    
                    $apiTokenDisplay = !empty($config['api_token']) 
                        ? '••••••••' . substr($config['api_token'], -4)
                        : '<span class="text-red-500">Not configured</span>';

                    $statusBadge = $isConfigured
                        ? '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>'
                        : '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Not Configured</span>';

                    // Render page content
                    ob_start();
                    ?>
                    
                    <?php if ($message): ?>
                    <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                    <?php endif; ?>

                    <div class="grid gap-6 md:grid-cols-2">
                        <!-- Status Card -->
                        <div class="card">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
                                <span class="material-symbols-rounded text-xl">info</span>
                                Configuration Status
                            </h3>
                            <dl class="space-y-3">
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Status</dt>
                                    <dd><?= $statusBadge ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Zone ID</dt>
                                    <dd class="font-mono text-sm"><?= $zoneIdDisplay ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">API Token</dt>
                                    <dd class="font-mono text-sm"><?= $apiTokenDisplay ?></dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Actions Card -->
                        <div class="card">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
                                <span class="material-symbols-rounded text-xl">bolt</span>
                                Actions
                            </h3>
                            
                            <?php if ($isConfigured): ?>
                            <p class="text-gray-600 text-sm mb-4">
                                Purge all cached content from Cloudflare. This happens automatically when you rebuild the content index.
                            </p>
                            <form method="POST">
                                <input type="hidden" name="_token" value="<?= htmlspecialchars($controller->csrfToken()) ?>">
                                <input type="hidden" name="action" value="purge">
                                <button type="submit" class="btn btn-primary flex items-center gap-2">
                                    <span class="material-symbols-rounded text-lg">delete_sweep</span>
                                    Purge Cache Now
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <p class="text-yellow-800 text-sm">
                                    <strong>Configuration required.</strong> Add the following to your <code>app/config/ava.php</code>:
                                </p>
                                <pre class="mt-3 p-3 bg-gray-800 text-gray-100 rounded text-xs overflow-x-auto">'cloudflare' => [
    'enabled'   => true,
    'zone_id'   => 'your-zone-id',
    'api_token' => 'your-api-token',
],</pre>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Info Card -->
                    <div class="card mt-6">
                        <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
                            <span class="material-symbols-rounded text-xl">help</span>
                            About Automatic Cache Purging
                        </h3>
                        <div class="prose prose-sm max-w-none text-gray-600">
                            <p>
                                When enabled, this plugin automatically purges your Cloudflare cache whenever the content index is rebuilt.
                                This ensures visitors always see the latest content after you publish changes.
                            </p>
                            <p class="mt-3">
                                <strong>When does a rebuild happen?</strong>
                            </p>
                            <ul class="mt-2 list-disc list-inside">
                                <li>Running <code>./ava rebuild</code> from the CLI</li>
                                <li>Clicking "Rebuild Index" in the admin dashboard</li>
                                <li>Automatic rebuilds in development mode (when <code>content_index.mode</code> is <code>auto</code>)</li>
                            </ul>
                            <p class="mt-3 text-xs text-gray-500">
                                Cloudflare® is a registered trademark of Cloudflare, Inc.
                            </p>
                        </div>
                    </div>

                    <?php
                    $content = ob_get_clean();

                    return $controller->renderPluginPage([
                        'title'      => 'Ava for Cloudflare®',
                        'icon'       => 'cloud',
                        'activePage' => 'cloudflare',
                    ], $content);
                },
            ];
            return $pages;
        });
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
            'handler'     => function ($args, $cli, $app) {
                $config = array_merge([
                    'enabled'   => false,
                    'zone_id'   => '',
                    'api_token' => '',
                ], $app->config('cloudflare', []));

                $cli->header('Cloudflare Integration Status');
                $cli->writeln('');

                // Status table
                $rows = [
                    ['Enabled', $config['enabled'] ? 'Yes' : 'No'],
                    ['Zone ID', !empty($config['zone_id']) ? substr($config['zone_id'], 0, 8) . '...' : '(not set)'],
                    ['API Token', !empty($config['api_token']) ? '••••' . substr($config['api_token'], -4) : '(not set)'],
                ];

                $cli->table(['Setting', 'Value'], $rows);

                $isConfigured = $config['enabled'] && !empty($config['zone_id']) && !empty($config['api_token']);
                
                $cli->writeln('');
                if ($isConfigured) {
                    $cli->success('✓ Cloudflare integration is active. Cache will be purged on rebuild.');
                } else {
                    $cli->warning('⚠ Cloudflare integration is not fully configured.');
                    $cli->writeln('');
                    $cli->info('Add to app/config/ava.php:');
                    $cli->writeln('');
                    $cli->writeln("  'cloudflare' => [");
                    $cli->writeln("      'enabled'   => true,");
                    $cli->writeln("      'zone_id'   => 'your-zone-id',");
                    $cli->writeln("      'api_token' => 'your-api-token',");
                    $cli->writeln("  ],");
                }

                return 0;
            },
        ],
        [
            'name'        => 'cloudflare:purge',
            'description' => 'Purge all Cloudflare cached content',
            'handler'     => function ($args, $cli, $app) {
                $config = array_merge([
                    'enabled'   => false,
                    'zone_id'   => '',
                    'api_token' => '',
                ], $app->config('cloudflare', []));

                $cli->header('Cloudflare Cache Purge');
                $cli->writeln('');

                if (!$config['enabled']) {
                    $cli->error('Cloudflare integration is not enabled.');
                    $cli->info("Set 'cloudflare.enabled' => true in ava.php");
                    return 1;
                }

                if (empty($config['zone_id']) || empty($config['api_token'])) {
                    $cli->error('Cloudflare zone_id and api_token are required.');
                    $cli->info('Run ./ava cloudflare:status for configuration help.');
                    return 1;
                }

                $cli->info('Purging Cloudflare cache...');

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
                    $cli->error('cURL error: ' . $curlError);
                    return 1;
                }

                $data = json_decode($response, true);

                if ($httpCode === 200 && ($data['success'] ?? false)) {
                    $cli->success('✓ Cloudflare cache purged successfully');
                    return 0;
                }

                // Extract error message
                $errors = $data['errors'] ?? [];
                $errorMessage = !empty($errors) 
                    ? implode(', ', array_map(fn($e) => $e['message'] ?? 'Unknown error', $errors))
                    : 'Unknown error (HTTP ' . $httpCode . ')';

                $cli->error('Cloudflare API error: ' . $errorMessage);
                return 1;
            },
        ],
    ],
];
