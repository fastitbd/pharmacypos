<?php

namespace App\Console\Commands;

use App\Services\PharmacyImportService;
use Illuminate\Console\Command;

class ImportPharmacyProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pharmacy:import-products 
                            {--file= : Path to the hisabkit_pharmacy_db.sql file} 
                            {--chunk=1000 : Number of records to insert per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import all pharmacy medicine products from hisabkit_pharmacy_db.sql into the database and sync with all branches.';

    /**
     * Execute the console command.
     */
    public function handle(PharmacyImportService $importService): int
    {
        $this->info("=================================================");
        $this->info("       PHARMACY PRODUCTS IMPORT TOOL            ");
        $this->info("=================================================");

        $defaultPath = base_path('hisabkit_pharmacy_db.sql');
        $filePath = $this->option('file') ?: $defaultPath;
        $chunkSize = (int) $this->option('chunk') ?: 1000;

        if (!file_exists($filePath)) {
            $this->error("Error: Database dump file not found at: {$filePath}");
            $this->line("Please place 'hisabkit_pharmacy_db.sql' in your project root ({$defaultPath}) or pass --file=/path/to/file.sql");
            return 1;
        }

        $this->info("Loading file: {$filePath}");
        $this->info("Counting total products...");

        $totalCount = $importService->countProductsInSql($filePath);
        $this->info("Found {$totalCount} products ready to import.");

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% (Elapsed: %elapsed:6s%, Remaining: %estimated:-6s%)');
        $progressBar->start();

        $result = $importService->importFromSqlFile(
            $filePath,
            function (int $batchCount, int $totalImported, int $totalTarget) use ($progressBar) {
                $progressBar->setProgress(min($totalImported, $totalTarget));
            },
            $chunkSize
        );

        $progressBar->finish();
        $this->newLine(2);

        if ($result['success']) {
            $this->info("=================================================");
            $this->info("  SUCCESSFULLY IMPORTED PHARMACY PRODUCTS!     ");
            $this->info("=================================================");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Products in File', $result['total_products']],
                    ['Imported Products', $result['imported_count']],
                    ['Branches Synced', $result['branches_synced']],
                    ['Time Taken', $result['time_taken']],
                ]
            );
            $this->info("All products are now live in the Product List, POS, and Purchase screens!");
            return 0;
        } else {
            $this->error("Import Failed: " . ($result['message'] ?? 'Unknown error'));
            return 1;
        }
    }
}
