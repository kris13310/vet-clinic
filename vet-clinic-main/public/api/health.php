<?php
declare(strict_types=1);
/** Open https://YOUR-SITE/api/health.php after deploying: every value should be true. Reveals no secrets. */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
set_exception_handler(static function (Throwable $e): void { error_log($e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => getenv('APP_DEBUG') ? $e->getMessage() : 'Server error. Set APP_DEBUG=1 in Vercel to see details.']); });
require_once dirname(__DIR__) . '/config.php';   // answers with an error message itself if the database cannot be reached
require_once __DIR__ . '/helpers.php';
$out = ['database' => true, 'tables' => false, 'sessions_table' => false, 'storage_configured' => (bool)(getenv('SUPABASE_URL') && getenv('SUPABASE_SERVICE_KEY')), 'storage_bucket' => false, 'timezone' => date_default_timezone_get()];
try { foreach (['users', 'pets', 'appointments', 'vaccinations', 'adoption_records', 'clinic_settings', 'notifications', 'clinic_closures', 'medical_reports'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1"); $out['tables'] = true; } catch (Throwable $e) { error_log($e->getMessage()); }
try { $pdo->query('SELECT 1 FROM php_sessions LIMIT 1'); $out['sessions_table'] = true; } catch (Throwable $e) { error_log($e->getMessage()); }
if ($out['storage_configured']) { [$code] = storage_call('GET', 'bucket/' . rawurlencode(storage_bucket())); $out['storage_bucket'] = $code === 200; }
$out['ok'] = $out['database'] && $out['tables'] && $out['sessions_table'] && $out['storage_configured'] && $out['storage_bucket'];
http_response_code($out['ok'] ? 200 : 503);
echo json_encode($out);
