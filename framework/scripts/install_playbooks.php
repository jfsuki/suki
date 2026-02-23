<?php
declare(strict_types=1);

// framework/scripts/install_playbooks.php
// Usage:
//   php framework/scripts/install_playbooks.php --list
//   php framework/scripts/install_playbooks.php --sector=FERRETERIA [--dry-run] [--overwrite]

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\PlaybookInstaller;

$opts = getopt('', ['sector::', 'dry-run', 'overwrite', 'list']);
$installer = new PlaybookInstaller();

if (isset($opts['list'])) {
    echo json_encode(['sectors' => $installer->listSectors()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$sectorKey = strtoupper(trim((string) ($opts['sector'] ?? '')));
if ($sectorKey === '') {
    fwrite(STDERR, "Falta --sector=SECTOR_KEY. Usa --list para ver opciones.\n");
    exit(1);
}

$result = $installer->installSector(
    $sectorKey,
    isset($opts['dry-run']),
    isset($opts['overwrite'])
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
