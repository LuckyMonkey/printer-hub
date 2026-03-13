<?php
declare(strict_types=1);

use PrinterHub\ApiController;
use PrinterHub\BatchRepository;
use PrinterHub\BatchPrintService;
use PrinterHub\CommandRunner;
use PrinterHub\CupsTransport;
use PrinterHub\Database;
use PrinterHub\HpBatchPdfService;
use PrinterHub\HpLabelService;
use PrinterHub\JobLogger;
use PrinterHub\JobService;
use PrinterHub\MultiPrinterPrintService;
use PrinterHub\PrintJobRepository;
use PrinterHub\PrintWorkflowService;
use PrinterHub\PrinterService;
use PrinterHub\PrinterRegistry;
use PrinterHub\RateLimiter;
use PrinterHub\RawSocketTransport;
use PrinterHub\SeriesBarcodeRepository;
use PrinterHub\SeriesPrintService;
use PrinterHub\SheetsBackupService;
use PrinterHub\BrotherTemplateClient;
use PrinterHub\ZebraLabelService;
use PrinterHub\ZebraImagePrintService;
use PrinterHub\ZebraPngRasterService;
use PrinterHub\ZebraQrLabelService;
use PrinterHub\ZplRasterService;

require_once __DIR__ . '/../src/CommandRunner.php';
require_once __DIR__ . '/../src/PrinterService.php';
require_once __DIR__ . '/../src/JobService.php';
require_once __DIR__ . '/../src/BatchCodec.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/BatchRepository.php';
require_once __DIR__ . '/../src/BatchPrintService.php';
require_once __DIR__ . '/../src/PrintJobRepository.php';
require_once __DIR__ . '/../src/SheetsBackupService.php';
require_once __DIR__ . '/../src/PrinterRegistry.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/JobLogger.php';
require_once __DIR__ . '/../src/SeriesBarcodeRepository.php';
require_once __DIR__ . '/../src/SeriesPrintService.php';
require_once __DIR__ . '/../src/RawSocketTransport.php';
require_once __DIR__ . '/../src/CupsTransport.php';
require_once __DIR__ . '/../src/ZebraLabelService.php';
require_once __DIR__ . '/../src/ZebraPngRasterService.php';
require_once __DIR__ . '/../src/ZebraQrLabelService.php';
require_once __DIR__ . '/../src/ZebraImagePrintService.php';
require_once __DIR__ . '/../src/BrotherTemplateClient.php';
require_once __DIR__ . '/../src/HpLabelService.php';
require_once __DIR__ . '/../src/HpBatchPdfService.php';
require_once __DIR__ . '/../src/MultiPrinterPrintService.php';
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
    $registry = new PrinterRegistry();
    $logger = new JobLogger($registry->logPath());
    $socketTransport = new RawSocketTransport();
    $cupsTransport = new CupsTransport($commands);
    $zebraService = new ZebraLabelService();
    $zebraPngRaster = new ZebraPngRasterService();
    $zebraQr = new ZebraQrLabelService($commands, $zebraPngRaster);
    $brotherService = new BrotherTemplateClient();
    $hpService = new HpLabelService();
    $hpBatchPdf = new HpBatchPdfService($commands);
    $sheetsBackup = new SheetsBackupService(getenv('GAPPS_WEBHOOK_URL') ?: null);
    $printJobs = new PrintJobRepository($database);
    $multiPrint = new MultiPrinterPrintService(
        registry: $registry,
        jobs: $printJobs,
        logger: $logger,
        socketTransport: $socketTransport,
        cupsTransport: $cupsTransport,
        zebra: $zebraService,
        zebraQr: $zebraQr,
        brother: $brotherService,
        hp: $hpService
    );

    $workflow = new PrintWorkflowService(
        $commands,
        $batches,
        $sheetsBackup,
        new ZplRasterService()
    );
    $zebraImagePrint = new ZebraImagePrintService(
        $registry,
        $printJobs,
        $logger,
        $cupsTransport,
        $zebraPngRaster
    );
    $seriesRepo = new SeriesBarcodeRepository($database);
    $seriesPrint = new SeriesPrintService($multiPrint, $seriesRepo, $logger);
    $batchPrint = new BatchPrintService(
        $multiPrint,
        $batches,
        $logger,
        $sheetsBackup,
        $registry,
        $cupsTransport,
        $socketTransport,
        $zebraService,
        $hpBatchPdf
    );

    $controller = new ApiController(
        new PrinterService($commands),
        new JobService($commands),
        $workflow,
        $multiPrint,
        new RateLimiter(),
        $registry,
        $zebraImagePrint,
        $seriesPrint,
        $batchPrint
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
