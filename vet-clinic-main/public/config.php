<?php
declare(strict_types=1);
/**
 * Database connection (Supabase PostgreSQL). Nothing secret is stored in this file:
 * set these in Vercel > Project > Settings > Environment Variables.
 *   DB_HOST      e.g. aws-0-ap-southeast-1.pooler.supabase.com   (Supabase > Connect > Transaction pooler)
 *   DB_PORT      6543 (transaction pooler)  or 5432 (session pooler)
 *   DB_NAME      postgres
 *   DB_USER      postgres.<your-project-ref>
 *   DB_PASSWORD  your database password
 */
$host = (string)getenv('DB_HOST'); $port = (string)(getenv('DB_PORT') ?: '6543'); $dbname = (string)(getenv('DB_NAME') ?: 'postgres');
$dbuser = (string)getenv('DB_USER'); $dbpass = (string)getenv('DB_PASSWORD');
try {
    if ($host === '' || $dbuser === '') throw new PDOException('DB_HOST / DB_USER environment variables are not set.');
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require", $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,   // required by Supabase's transaction pooler (no server-side prepared statements)
    ]);
} catch (PDOException $e) {
    error_log('Veterinary DB connection failed: ' . $e->getMessage());
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Cannot connect to the database. Check the DB_* environment variables in Vercel and that supabase/schema.sql was run.']);
    exit;
}
unset($host, $port, $dbname, $dbuser, $dbpass); // keep the credentials out of the rest of the request
