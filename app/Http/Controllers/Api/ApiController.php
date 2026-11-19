<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BranchBrand;
use App\Models\BranchCategory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\PharmacyImportService;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    /**
     * Default branch ID (Must be 2 as requested)
     */
    protected int $defaultBranchId = 2;

    protected function getBranchId(Request $request): int
    {
        return (int) $request->get('branch_id', $this->defaultBranchId);
    }

    /**
     * Get products for default branch (branch_id = 2)
     */
    public function products(Request $request)
    {
        $branchId = $this->getBranchId($request);

        $productIds = BranchProduct::where('branch_id', $branchId)->pluck('product_id')->toArray();

        $query = Product::with(['category', 'brand', 'unit'])->where('is_service', 0);

        if (!empty($productIds)) {
            $query->whereIn('id', $productIds);
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('barcode', 'LIKE', "%{$search}%")
                  ->orWhere('generic_name', 'LIKE', "%{$search}%")
                  ->orWhere('company', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('generic_name')) {
            $query->where('generic_name', 'LIKE', '%' . $request->get('generic_name') . '%');
        }

        if ($request->filled('company')) {
            $query->where('company', 'LIKE', '%' . $request->get('company') . '%');
        }

        if ($request->filled('dosage_form')) {
            $query->where('dosage_form', $request->get('dosage_form'));
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->get('product_type'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->get('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->get('brand_id'));
        }

        if ($request->get('all') == '1') {
            $limit = min((int) $request->get('limit', 5000), 10000);
            $products = $query->orderBy('id', 'desc')->limit($limit)->get();

            return response()->json([
                'status' => true,
                'message' => 'Products retrieved successfully',
                'branch_id' => $branchId,
                'count' => $products->count(),
                'data' => $products,
            ]);
        }

        $perPage = max(1, min((int) $request->get('per_page', 50), 200));
        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Products retrieved successfully',
            'branch_id' => $branchId,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'data' => $paginator->items(),
        ]);
    }

    /**
     * Dedicated Pharmacy Products API
     */
    public function pharmacyProducts(Request $request)
    {
        $request->merge(['product_type' => $request->get('product_type', 'medicine')]);
        return $this->products($request);
    }

    /**
     * Get single product details by ID or barcode
     */
    public function pharmacyProductDetails($identifier, Request $request)
    {
        $branchId = $this->getBranchId($request);

        $product = Product::with(['category', 'brand', 'unit.related_unit'])
            ->where(function ($q) use ($identifier) {
                if (is_numeric($identifier)) {
                    $q->where('id', $identifier)->orWhere('barcode', $identifier);
                } else {
                    $q->where('barcode', $identifier);
                }
            })
            ->first();

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }

        $stock = function_exists('product_stock') ? product_stock($product, $branchId) : 0;
        $expireBatches = \App\Models\PurchaseItem::where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->whereNotNull('expire_date')
            ->where('expire_date', '!=', '')
            ->where('stock_qty', '>', 0)
            ->select('expire_date', \Illuminate\Support\Facades\DB::raw('SUM(stock_qty) as available_stock'))
            ->groupBy('expire_date')
            ->orderBy('expire_date', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Product details retrieved successfully',
            'branch_id' => $branchId,
            'product' => $product,
            'current_stock' => $stock,
            'expire_batches' => $expireBatches,
        ]);
    }

    /**
     * Trigger database import from SQL dump via API
     */
    public function importPharmacyDatabase(Request $request, PharmacyImportService $importService)
    {
        $filePath = $request->get('file_path') ?: base_path('hisabkit_pharmacy_db.sql');
        $chunkSize = (int) $request->get('chunk', 1000);

        if (!file_exists($filePath)) {
            return response()->json([
                'status' => false,
                'message' => "File not found: {$filePath}",
            ], 404);
        }

        $result = $importService->importFromSqlFile($filePath, null, $chunkSize);

        return response()->json([
            'status' => $result['success'],
            'message' => $result['message'],
            'data' => $result,
        ], $result['success'] ? 200 : 500);
    }

    /**
     * Get categories for default branch (branch_id = 2)
     */
    public function categories(Request $request)
    {
        $branchId = $this->getBranchId($request);

        $categoryIds = BranchCategory::where('branch_id', $branchId)->pluck('category_id')->toArray();

        $query = Category::query();

        if (!empty($categoryIds)) {
            $query->whereIn('id', $categoryIds);
        }

        $categories = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Categories retrieved successfully',
            'branch_id' => $branchId,
            'count' => $categories->count(),
            'data' => $categories,
        ]);
    }

    /**
     * Get brands for default branch (branch_id = 2)
     */
    public function brands(Request $request)
    {
        $branchId = $this->getBranchId($request);

        $brandIds = BranchBrand::where('branch_id', $branchId)->pluck('brand_id')->toArray();

        $query = Brand::query();

        if (!empty($brandIds)) {
            $query->whereIn('id', $brandIds);
        }

        $brands = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Brands retrieved successfully',
            'branch_id' => $branchId,
            'count' => $brands->count(),
            'data' => $brands,
        ]);
    }

    /**
     * Get customers for default branch (branch_id = 2)
     */
    public function customers(Request $request)
    {
        $branchId = $this->getBranchId($request);

        $customers = Customer::where(function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)
              ->orWhereNull('branch_id');
        })
        ->orderBy('id', 'desc')
        ->get();

        return response()->json([
            'status' => true,
            'message' => 'Customers retrieved successfully',
            'branch_id' => $branchId,
            'count' => $customers->count(),
            'data' => $customers,
        ]);
    }

    /**
     * Get suppliers for default branch (branch_id = 2)
     */
    public function suppliers(Request $request)
    {
        $branchId = $this->getBranchId($request);

        $suppliers = Supplier::where(function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)
              ->orWhereNull('branch_id');
        })
        ->orderBy('id', 'desc')
        ->get();

        return response()->json([
            'status' => true,
            'message' => 'Suppliers retrieved successfully',
            'branch_id' => $branchId,
            'count' => $suppliers->count(),
            'data' => $suppliers,
        ]);
    }
}
