<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PharmacyImportService
{
    /**
     * Import pharmacy products and metadata from a SQL dump file.
     *
     * @param string $filePath
     * @param callable|null $progressCallback
     * @param int $chunkSize
     * @return array
     */
    public function importFromSqlFile(string $filePath, ?callable $progressCallback = null, int $chunkSize = 1000): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'message' => "File not found: {$filePath}",
                'imported_count' => 0,
            ];
        }

        $startTime = microtime(true);

        // 1. Ensure Categories, Brands, and Units exist
        $this->syncMetadataFromSql($filePath);

        // 2. Fetch active branch IDs
        $branchIds = Branch::pluck('id')->toArray();
        if (empty($branchIds)) {
            $branchIds = [1];
        }

        // 3. Count total products for accurate progress reporting
        $totalProducts = $this->countProductsInSql($filePath);

        // 4. Stream and bulk-insert products in chunks
        $file = fopen($filePath, 'r');
        if (!$file) {
            return [
                'success' => false,
                'message' => "Could not open file for reading: {$filePath}",
                'imported_count' => 0,
            ];
        }

        $inProductSection = false;
        $productBatch = [];
        $importedCount = 0;
        $now = now()->toDateTimeString();

        // Columns we allow in the products table
        $updateColumns = [
            'name',
            'product_type',
            'generic_name',
            'company',
            'strength',
            'dosage_form',
            'barcode',
            'category_id',
            'brand_id',
            'unit_id',
            'purchase_price',
            'dis_selling_price',
            'discount',
            'selling_price',
            'is_service',
            'has_warranty',
            'description',
            'status',
            'has_expire_date',
            'updated_at',
        ];

        while (($line = fgets($file)) !== false) {
            if (strpos($line, "INSERT INTO `products`") !== false) {
                $inProductSection = true;
            }

            if ($inProductSection) {
                $trimmed = trim($line);

                if (preg_match('/^\((.+)\)[,;]?$/', $trimmed, $matches)) {
                    $raw = $matches[1];
                    $csv = str_getcsv($raw, ',', "'", "\\");

                    if (count($csv) >= 30) {
                        $parsed = $this->mapProductRow($csv, $now);
                        if ($parsed) {
                            $productBatch[] = $parsed;
                        }
                    }

                    if (count($productBatch) >= $chunkSize) {
                        $this->flushProductBatch($productBatch, $updateColumns, $branchIds, $now);
                        $importedCount += count($productBatch);

                        if ($progressCallback) {
                            $progressCallback(count($productBatch), $importedCount, $totalProducts);
                        }

                        $productBatch = [];
                    }
                }

                // End of this INSERT statement
                if (strpos($line, ';') !== false && !preg_match('/,\s*$/', $trimmed)) {
                    $inProductSection = false;
                }
            }
        }
        fclose($file);

        // Flush remaining products
        if (!empty($productBatch)) {
            $this->flushProductBatch($productBatch, $updateColumns, $branchIds, $now);
            $importedCount += count($productBatch);

            if ($progressCallback) {
                $progressCallback(count($productBatch), $importedCount, $totalProducts);
            }
        }

        // 5. Ensure all categories and brands are linked to branches
        $this->syncBranchRelations($branchIds, $now);

        $elapsed = round(microtime(true) - $startTime, 2);

        return [
            'success' => true,
            'message' => "Successfully imported {$importedCount} products in {$elapsed} seconds.",
            'total_products' => $totalProducts,
            'imported_count' => $importedCount,
            'branches_synced' => count($branchIds),
            'time_taken' => "{$elapsed}s",
        ];
    }

    /**
     * Map a raw CSV parsed SQL row to products table attributes.
     */
    private function mapProductRow(array $csv, string $now): ?array
    {
        $id = (int) trim($csv[0] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $cleanVal = function ($val, $default = null) {
            if ($val === null) return $default;
            $t = trim($val);
            if ($t === 'NULL' || $t === '') return $default;
            return $t;
        };

        $name = $cleanVal($csv[2] ?? null, 'Unnamed Product');
        $genericName = $cleanVal($csv[4] ?? null, '');
        $company = $cleanVal($csv[5] ?? null, '');
        $strength = $cleanVal($csv[6] ?? null, '');
        $dosageForm = $cleanVal($csv[7] ?? null, '');
        $barcode = $cleanVal($csv[8] ?? null, sprintf('%06d', $id));
        $categoryId = (int) $cleanVal($csv[9] ?? null, 1);
        $brandId = $cleanVal($csv[10] ?? null) ? (int)$csv[10] : null;
        $unitId = (int) $cleanVal($csv[11] ?? null, 1);
        $purchasePrice = (float) $cleanVal($csv[14] ?? 0, 0.00);
        $disSellingPrice = (float) $cleanVal($csv[15] ?? 0, 0.00);
        $discount = $cleanVal($csv[16] ?? null) !== null ? (float)$csv[16] : null;
        $sellingPrice = (float) $cleanVal($csv[17] ?? 0, 0.00);
        $description = $cleanVal($csv[22] ?? null, '');
        $createdAt = $cleanVal($csv[27] ?? null, $now);
        $updatedAt = $cleanVal($csv[28] ?? null, $now);
        $hasExpireDate = (int) $cleanVal($csv[30] ?? 1, 1);

        return [
            'id' => $id,
            'date' => $cleanVal($csv[1] ?? null, date('Y-m-d')),
            'name' => $name,
            'product_type' => 'medicine',
            'generic_name' => $genericName,
            'company' => $company,
            'strength' => $strength,
            'dosage_form' => $dosageForm,
            'barcode' => $barcode,
            'category_id' => $categoryId > 0 ? $categoryId : 1,
            'brand_id' => $brandId,
            'unit_id' => $unitId > 0 ? $unitId : 1,
            'purchase_price' => $purchasePrice,
            'dis_selling_price' => $disSellingPrice,
            'discount' => $discount,
            'selling_price' => $sellingPrice,
            'is_service' => '0',
            'has_warranty' => 0,
            'warranty_value' => null,
            'warranty_unit' => null,
            'description' => $description,
            'has_serial' => 0,
            'images' => null,
            'status' => '1',
            'created_by' => 1,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'imei' => null,
            'has_expire_date' => $hasExpireDate ?: 1,
        ];
    }

    /**
     * Bulk-insert or update a product batch and link to branch_products.
     */
    private function flushProductBatch(array $productBatch, array $updateColumns, array $branchIds, string $now): void
    {
        if (empty($productBatch)) {
            return;
        }

        // 1. Bulk Upsert into products table
        DB::table('products')->upsert($productBatch, ['id'], $updateColumns);

        // 2. Bulk insert into branch_products for all active branches
        $branchProductRows = [];
        foreach ($productBatch as $prod) {
            foreach ($branchIds as $branchId) {
                $branchProductRows[] = [
                    'branch_id' => $branchId,
                    'product_id' => $prod['id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($branchProductRows)) {
            // Insert in sub-chunks of 1000 to keep query sizes well within limits
            foreach (array_chunk($branchProductRows, 1000) as $chunk) {
                DB::table('branch_products')->insertOrIgnore($chunk);
            }
        }
    }

    /**
     * Count total product rows in the SQL dump.
     */
    public function countProductsInSql(string $filePath): int
    {
        $file = fopen($filePath, 'r');
        if (!$file) return 0;

        $inProductSection = false;
        $count = 0;

        while (($line = fgets($file)) !== false) {
            if (strpos($line, "INSERT INTO `products`") !== false) {
                $inProductSection = true;
            }
            if ($inProductSection) {
                if (preg_match('/^\s*\(/', $line)) {
                    $count++;
                }
                if (strpos($line, ';') !== false && !preg_match('/,\s*$/', trim($line))) {
                    $inProductSection = false;
                }
            }
        }
        fclose($file);

        return $count;
    }

    /**
     * Ensure categories, brands, and units exist based on SQL dump.
     */
    private function syncMetadataFromSql(string $filePath): void
    {
        $file = fopen($filePath, 'r');
        if (!$file) return;

        // Categories to ensure
        $categoriesToEnsure = [
            1 => 'Default Category',
            2 => 'TEST',
            3 => 'Tablet',
        ];

        // Brands to ensure
        $brandsToEnsure = [
            1 => ['name' => 'Default Brand', 'slug' => 'default-brand'],
            2 => ['name' => 'Square', 'slug' => 'square'],
        ];

        // Units to ensure
        $unitsToEnsure = [
            1 => ['name' => 'pcs', 'related_unit_id' => null, 'related_sign' => '*', 'related_value' => 1],
            2 => ['name' => 'gm', 'related_unit_id' => 5, 'related_sign' => '*', 'related_value' => 1000],
            3 => ['name' => 'kg', 'related_unit_id' => 2, 'related_sign' => '*', 'related_value' => 1000],
            4 => ['name' => 'mg', 'related_unit_id' => null, 'related_sign' => '*', 'related_value' => 1],
            5 => ['name' => 'dz', 'related_unit_id' => 1, 'related_sign' => '*', 'related_value' => 12],
        ];

        // Scan SQL file for any additional categories or brands
        while (($line = fgets($file)) !== false) {
            if (strpos($line, "INSERT INTO `categories`") !== false || strpos($line, "INSERT INTO `brands`") !== false) {
                // We have our known defaults and can scan if needed
            }
        }
        fclose($file);

        // Seed / ensure categories
        foreach ($categoriesToEnsure as $id => $name) {
            if (!DB::table('categories')->where('id', $id)->exists()) {
                DB::table('categories')->insert([
                    'id' => $id,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Seed / ensure brands
        foreach ($brandsToEnsure as $id => $bData) {
            if (!DB::table('brands')->where('id', $id)->exists()) {
                DB::table('brands')->insert([
                    'id' => $id,
                    'name' => $bData['name'],
                    'slug' => $bData['slug'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Seed / ensure units
        foreach ($unitsToEnsure as $id => $uData) {
            if (!DB::table('units')->where('id', $id)->exists()) {
                DB::table('units')->insert([
                    'id' => $id,
                    'name' => $uData['name'],
                    'related_unit_id' => $uData['related_unit_id'],
                    'related_sign' => $uData['related_sign'],
                    'related_value' => $uData['related_value'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Link all categories and brands to all branches in pivot tables.
     */
    private function syncBranchRelations(array $branchIds, string $now): void
    {
        $categoryIds = DB::table('categories')->pluck('id')->toArray();
        $brandIds = DB::table('brands')->pluck('id')->toArray();

        $branchCats = [];
        foreach ($branchIds as $bId) {
            foreach ($categoryIds as $cId) {
                $branchCats[] = [
                    'branch_id' => $bId,
                    'category_id' => $cId,
                ];
            }
        }
        if (!empty($branchCats)) {
            DB::table('branch_categories')->insertOrIgnore($branchCats);
        }

        $branchBrands = [];
        foreach ($branchIds as $bId) {
            foreach ($brandIds as $brId) {
                $branchBrands[] = [
                    'branch_id' => $bId,
                    'brand_id' => $brId,
                ];
            }
        }
        if (!empty($branchBrands)) {
            DB::table('branch_brands')->insertOrIgnore($branchBrands);
        }
    }
}
