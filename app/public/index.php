<?php
declare(strict_types=1);

use PrinterHub\ApiController;
use PrinterHub\BatchRepository;
use PrinterHub\CommandRunner;
use PrinterHub\Database;
use PrinterHub\JobService;
use PrinterHub\PrintWorkflowService;
use PrinterHub\PrinterService;
use PrinterHub\SheetsBackupService;
use PrinterHub\ZplRasterService;

require_once __DIR__ . '/../src/CommandRunner.php';
require_once __DIR__ . '/../src/PrinterService.php';
require_once __DIR__ . '/../src/JobService.php';
require_once __DIR__ . '/../src/BatchCodec.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/BatchRepository.php';
require_once __DIR__ . '/../src/SheetsBackupService.php';
require_once __DIR__ . '/../src/ZplRasterService.php';
require_once __DIR__ . '/../src/PrintWorkflowService.php';
require_once __DIR__ . '/../src/ApiController.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$queryString = parse_url($uri, PHP_URL_QUERY) ?: '';
$query = [];
parse_str($queryString, $query);

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    http_response_code(204);
    exit;
}

if (str_starts_with($path, '/api/')) {
    $commands = new CommandRunner();
    $database = new Database();
    $batches = new BatchRepository($database);
    $workflow = new PrintWorkflowService(
        $commands,
        $batches,
        new SheetsBackupService(getenv('GAPPS_WEBHOOK_URL') ?: null),
        new ZplRasterService()
    );

    $controller = new ApiController(
        new PrinterService($commands),
        new JobService($commands),
        $workflow
    );
    $controller->handle($method, $path, $query);
    exit;
}

if ($path === '/') {
    header('Location: /ui/');
    exit;
}

http_response_code(404);
header('Content-Type: text/plain');
echo "Not Found\n";
