<?php

namespace App\Http\Controllers\Backend;

use App\Models\Unit;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Variation;
use App\Models\BranchBrand;
use App\Models\Rack;
use App\Models\BranchRack;
use App\Models\ProductSize;
use App\Models\ProductColor;
use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use App\Models\BranchProduct;
use App\Models\BranchCategory;
use App\Models\BusinessSetting;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $userBranchId = auth()->user() ? auth()->user()->branch_id : 1;
        $filterBranchId = session('branch_filter_id', $userBranchId);
        $targetBranchId = ($userBranchId == 1) ? $filterBranchId : $userBranchId;

        $data['product_id'] = $request->product_id;
        $data['category_id'] = $request->category_id;
        $data['sub_category_id'] = $request->sub_category_id;
        $data['barcode'] = $request->barcode;

        if (env('APP_SUB_CATEGORY') == 'yes') {
            $data['subCategories'] = $request->filled('category_id')
                ? SubCategory::where('category_id', $request->category_id)->orderBy('name', 'asc')->get(['id', 'name'])
                : SubCategory::orderBy('name', 'asc')->get(['id', 'name']);
        } else {
            $data['subCategories'] = collect();
        }

        if ($targetBranchId) {
            $data['categories'] = Category::whereExists(function ($q) use ($targetBranchId) {
                $q->select(DB::raw(1))
                    ->from('branch_categories')
                    ->whereColumn('branch_categories.category_id', 'categories.id')
                    ->where('branch_categories.branch_id', $targetBranchId);
            })->orderBy('name', 'asc')->get(['id', 'name']);
        } else {
            $data['categories'] = Category::orderBy('name', 'asc')->get(['id', 'name']);
        }

        $query = Product::with([
            'brand',
            'category',
            'subCategory',
            'unit.related_unit',
            'product_variations.size',
            'product_variations.color'
        ])->where('is_service', 0);

        if ($userBranchId != 1) {
            $query->whereExists(function ($q) use ($userBranchId) {
                $q->select(DB::raw(1))
                    ->from('branch_products')
                    ->whereColumn('branch_products.product_id', 'products.id')
                    ->where('branch_products.branch_id', $userBranchId);
            });
        } elseif ($filterBranchId && empty($request->barcode) && empty($request->product_id)) {
            $query->whereExists(function ($q) use ($filterBranchId) {
                $q->select(DB::raw(1))
                    ->from('branch_products')
                    ->whereColumn('branch_products.product_id', 'products.id')
                    ->where('branch_products.branch_id', $filterBranchId);
            });
        }

        $data['com'] = BusinessSetting::where('id', 6)->first();

        if ($request->filled('product_id')) {
            $query->where('id', $request->product_id);
        }

        if ($request->filled('barcode')) {
            $barcodeVal = trim($request->barcode);
            $resolved = function_exists('resolveProductAndVariationFromBarcode') ? resolveProductAndVariationFromBarcode($barcodeVal) : ['product_id' => null];
            if (!empty($resolved['product_id'])) {
                $query->where('id', $resolved['product_id']);
            } else {
                $query->where(function($q) use ($barcodeVal) {
                    $q->where('barcode', $barcodeVal)
                      ->orWhere('barcode', 'like', "%{$barcodeVal}%")
                      ->orWhere('name', 'like', "%{$barcodeVal}%")
                      ->orWhereHas('product_variations', function($vq) use ($barcodeVal) {
                          $vq->where('barcode', $barcodeVal)->orWhere('barcode', 'like', "%{$barcodeVal}%");
                      });
                });
            }
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('sub_category_id')) {
            $query->where('sub_category_id', $request->sub_category_id);
        }

        $data['products'] = $query
            ->orderBy('created_at', 'DESC')
            ->paginate(10)
            ->appends($request->all());

        return view('backend.pages.product.index', $data);
    }

    public function create()
    {

        $userBranchId = auth()->user()->branch_id;
        $filterBranchId = session('branch_filter_id', auth()->user()->branch_id);

        if ($userBranchId == 1) {
            if ($filterBranchId) {
                $categoryds = BranchCategory::where('branch_id', $filterBranchId)->pluck('category_id');
                $brandids = BranchBrand::where('branch_id', $filterBranchId)->pluck('brand_id');
                $data['categories'] = Category::whereIn('id', $categoryds)->orderBy('id', 'desc')->get();
                $data['brands'] = Brand::whereIn('id', $brandids)->orderBy('id', 'desc')->get();
            } else {
                $data['categories'] = Category::orderBy('id', 'desc')->get();
                $data['brands'] = Brand::orderBy('id', 'desc')->get();
            }
        } else {
            $categoryds = BranchCategory::where('branch_id', $userBranchId)->pluck('category_id');
            $brandids = BranchBrand::where('branch_id', $userBranchId)->pluck('brand_id');
            $data['categories'] = Category::whereIn('id', $categoryds)->orderBy('id', 'desc')->get();
            $data['brands'] = Brand::whereIn('id', $brandids)->orderBy('id', 'desc')->get();
        }

        // $brands = Brand::all();
        // $categories = Category::all();
        $data['subCategories'] = SubCategory::orderBy('name', 'asc')->get();
        $data['warranties'] = \App\Models\Warranty::where('status', 1)->orderBy('id', 'asc')->get();
        $variations = Variation::all();
        $sizes = ProductSize::all();
        $colors = ProductColor::all();
        $units = Unit::all();
        $suppliers = Supplier::all();
        $allBranch = Branch::get();
        $warranties = $data['warranties'];
        return view('backend.pages.product.create', $data, compact('allBranch', 'sizes', 'colors', 'variations', 'units', 'suppliers', 'warranties'));
    }

    function generateUniqueBarcode()
    {
        do {
            // always 6 digit (leading zero সহ)
            $barcode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

            $exists = \App\Models\Product::where('barcode', $barcode)->exists();
        } while ($exists);

        return $barcode;
    }

    public function store(Request $request)
    {
        // dd($request->all());

        $request->validate(
            [
                'name' => 'required',
                'barcode' => 'nullable|unique:products',
                'category_id' => 'required',
                'unit_id' => is_unit_enabled() ? 'required' : 'nullable',
                'purchase_price' => 'required',
                'selling_price' => 'required',
                'status' => 'required',
            ],
            [
                'barcode.unique' => 'The product barcode has already been taken !',
            ]
        );

        $product = new Product();
        $product->name = $request->name;
        $product->date = date('Y-m-d');
        if (empty($request->barcode)) {
            $product->barcode = $this->generateUniqueBarcode();
        } else {
            $product->barcode = $request->barcode;
        }

        $product->is_service = 0;
        $product->category_id = $request->category_id;
        $product->sub_category_id = $request->sub_category_id;
        $product->brand_id = $request->brand_id;
        $product->unit_id = $request->unit_id ?? (is_unit_enabled() ? null : \App\Models\Unit::first()?->id);
        $product->main_qty = $request->main_qty;
        $product->has_serial = $request->has_serial;
        $product->purchase_price = $request->purchase_price !== null ? (float) $request->purchase_price : 0;
        $dis_selling_price = $request->dis_selling_price !== null ? (float) $request->dis_selling_price : 0;
        $product->dis_selling_price = $dis_selling_price;
        $discount = $request->discount;
        if (!empty($discount) && str_contains($discount, '%')) {
            $percent = (float) str_replace('%', '', $discount);
            $discount = ($dis_selling_price * $percent) / 100;
        } elseif ($discount !== null && $discount !== '') {
            $discount = (float) $discount;
        } else {
            $discount = 0;
        }
        $product->discount = $discount;
        $product->selling_price = $request->selling_price !== null ? (float) $request->selling_price : ($dis_selling_price - $discount);
        $product->status = $request->status;
        $product->description = $request->description;
        $product->imei = $request->imei;

        // Medicine and Expire Date Configuration
        $product->product_type = $request->product_type ?? 'general';
        $product->generic_name = $request->generic_name;
        $product->company = $request->company;
        $product->strength = $request->strength;
        $product->dosage_form = $request->dosage_form;
        $product->has_expire_date = $request->has_expire_date ? 1 : 0;

        // Warranty configuration
        $product->warranty_id = $request->warranty_id ?? null;
        if ($request->filled('warranty_id')) {
            $selectedWarranty = \App\Models\Warranty::find($request->warranty_id);
            if ($selectedWarranty) {
                $product->has_warranty = 1;
                $product->warranty_value = $request->filled('warranty_value') ? $request->warranty_value : $selectedWarranty->duration;
                $product->warranty_unit = $request->filled('warranty_unit') ? $request->warranty_unit : $selectedWarranty->period;
            }
        } elseif ($request->filled('warranty_value') || $request->has_warranty == 1) {
            $product->has_warranty = 1;
            $product->warranty_value = $request->warranty_value ?? null;
            $product->warranty_unit = $request->warranty_unit ?? 'Month';
        } else {
            $product->has_warranty = 0;
            $product->warranty_value = null;
            $product->warranty_unit = null;
        }
        $product->created_by = Auth::user()->id;

        if ($product->save()) {
            if ($request->size != null || $request->color != null) {
                $sizes = $request->size ?? [null];
                $colors = $request->color ?? [null];
                foreach ($sizes as $size) {
                    foreach ($colors as $color) {
                        $product->product_variations()->create([
                            'product_id' => $product->id,
                            'size_id' => $size,
                            'color_id' => $color,
                        ]);
                    }
                }
            }
        }

        $image = $request->file('images');
        if ($image) {
            $imgName = date('YmdHi') . $image->getClientOriginalName();
            $image->move(public_path('uploads/products/'), $imgName);
            $product->images = $imgName;
        }

        DB::transaction(function () use ($request, $product) {
            if (auth()->user()->branch_id == 1) {
                foreach ($request->branch_id as $branch_id) {
                    // Ensure the category exists in the branch
                    $categoryExists = DB::table('branch_categories')
                        ->where('branch_id', $branch_id)
                        ->where('category_id', $product->category_id)
                        ->exists();

                    if (!$categoryExists) {
                        DB::table('branch_categories')->insert([
                            'branch_id' => $branch_id,
                            'category_id' => $product->category_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Ensure the brand exists in the branch
                    if ($product->brand_id) {
                        $brandExists = DB::table('branch_brands')
                            ->where('branch_id', $branch_id)
                            ->where('brand_id', $product->brand_id)
                            ->exists();

                        if (!$brandExists) {
                            DB::table('branch_brands')->insert([
                                'branch_id' => $branch_id,
                                'brand_id' => $product->brand_id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    // Save product to the branch
                    BranchProduct::create([
                        'product_id' => $product->id,
                        'branch_id' => $branch_id,
                    ]);
                }
            } else {
                $branch_id = auth()->user()->branch_id;

                // Ensure the category exists in the user's branch
                $categoryExists = DB::table('branch_categories')
                    ->where('branch_id', $branch_id)
                    ->where('category_id', $product->category_id)
                    ->exists();

                if (!$categoryExists) {
                    DB::table('branch_categories')->insert([
                        'branch_id' => $branch_id,
                        'category_id' => $product->category_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Ensure the brand exists in the user's branch
                if ($product->brand_id) {
                    $brandExists = DB::table('branch_brands')
                        ->where('branch_id', $branch_id)
                        ->where('brand_id', $product->brand_id)
                        ->exists();

                    if (!$brandExists) {
                        DB::table('branch_brands')->insert([
                            'branch_id' => $branch_id,
                            'brand_id' => $product->brand_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Save product to the user's branch
                BranchProduct::create([
                    'product_id' => $product->id,
                    'branch_id' => $branch_id,
                ]);
            }
        });

        $product->save();
        $product->load('category', 'brand');
        session()->flash('success', __('Product created successfully!'));
        logActivity('Create Product', "Product '{$product->name}' created", $product);
        return Redirect()->route('product.index');
    }

    public function edit(string $id)
    {
        $data['brands'] = Brand::all();
        $data['categories'] = Category::all();
        $data['subCategories'] = SubCategory::orderBy('name', 'asc')->get();
        $data['sizes'] = ProductSize::all();
        $data['colors'] = ProductColor::all();
        $data['units'] = Unit::all();
        $data['allBranch'] = Branch::get();
        $selectedVariations = ProductVariation::where('product_id', $id)->get();
        $data['selectedColorIds'] = $selectedVariations->pluck('color_id')->unique()->toArray();
        $data['selectedSizeIds'] = $selectedVariations->pluck('size_id')->unique()->toArray();
        
        $lockedSizeIds = [];
        $lockedColorIds = [];
        foreach ($selectedVariations as $pv) {
            if ($pv->hasTransactions()) {
                if ($pv->size_id) {
                    $lockedSizeIds[] = $pv->size_id;
                }
                if ($pv->color_id) {
                    $lockedColorIds[] = $pv->color_id;
                }
            }
        }
        $data['lockedSizeIds'] = array_unique($lockedSizeIds);
        $data['lockedColorIds'] = array_unique($lockedColorIds);

        $data['data'] = Product::with(['brand', 'category', 'unit.related_unit', 'warranty'])->where('id', $id)->first();
        $data['warranties'] = \App\Models\Warranty::where('status', 1)->orderBy('id', 'asc')->get();

        return view('backend.pages.product.edit', $data);
    }

    public function update(Request $request, string $id)
    {
        // dd($request->all());
        $request->validate([
            'name' => 'required',
            'barcode' => 'nullable|unique:products,barcode,' . $id,
            'category_id' => 'required',
            'purchase_price' => 'required',
            'selling_price' => 'required',
            'status' => 'required',
        ], [
            'barcode.unique' => 'The product barcode has already been taken !',
        ]);

        $product = Product::findorfail($id);

        if ($request->has('unit_id') && $request->unit_id != $product->unit_id) {
            if ($product->hasTransactions()) {
                session()->flash('error', __('Cannot change product unit because this product already has transaction history.'));
                return back();
            }
        }

        // Check if any variation being deleted has transactions/stock
        $existing = ProductVariation::where('product_id', $product->id)->get();
        $sizes = $request->size ?? [];
        $colors = $request->color ?? [];

        // Ensure locked/transaction size & color IDs are preserved even if omitted by browser due to disabled attribute
        foreach ($existing as $old) {
            if ($old->hasTransactions()) {
                if ($old->size_id && !in_array($old->size_id, $sizes)) {
                    $sizes[] = (string)$old->size_id;
                }
                if ($old->color_id && !in_array($old->color_id, $colors)) {
                    $colors[] = (string)$old->color_id;
                }
            }
        }

        $new = collect();
        if (!empty($sizes) && !empty($colors)) {
            foreach ($sizes as $size) {
                foreach ($colors as $color) {
                    $new->push([$size, $color]);
                }
            }
        } elseif (!empty($sizes) && empty($colors)) {
            foreach ($sizes as $size) {
                $new->push([$size, null]);
            }
        } elseif (empty($sizes) && !empty($colors)) {
            foreach ($colors as $color) {
                $new->push([null, $color]);
            }
        }

        $cannotDelete = [];
        foreach ($existing as $old) {
            $exists = $new->contains(function ($v) use ($old) {
                return $v[0] == $old->size_id && $v[1] == $old->color_id;
            });

            if (!$exists && $old->hasTransactions()) {
                $sizeName = $old->size->size ?? '';
                $colorName = $old->color->color ?? '';
                $comb = [];
                if ($sizeName) $comb[] = "Size: $sizeName";
                if ($colorName) $comb[] = "Color: $colorName";
                $cannotDelete[] = implode(', ', $comb) ?: 'Default Variation';
            }
        }

        if (!empty($cannotDelete)) {
            session()->flash('error', __('Cannot remove variation(s) (:variations) because they have stock or purchase history.', ['variations' => implode(' | ', $cannotDelete)]));
            return back();
        }
        $product->name = $request->name;
        $numberBarcode = rand(000000, 999999);
        if ($product->barcode == NULL) {
            $product->barcode = $numberBarcode;
        } else {
            $product->barcode = $request->barcode;
        }
        $product->category_id = $request->category_id;
        $product->sub_category_id = $request->sub_category_id;
        $product->brand_id = $request->brand_id;
        $product->unit_id = $request->unit_id ?? $product->unit_id;
        $product->main_qty = $request->main_qty;
        $product->purchase_price = $request->purchase_price !== null ? (float) $request->purchase_price : 0;
        $discount = $request->discount;
        $dis_selling_price = $request->dis_selling_price !== null ? (float) $request->dis_selling_price : 0;
        if (!empty($discount) && str_contains($discount, '%')) {
            $percent = (float) str_replace('%', '', $discount);
            $discount = ($dis_selling_price * $percent) / 100;
        } elseif ($discount !== null && $discount !== '') {
            $discount = (float) $discount;
        } else {
            $discount = 0;
        }
        $product->discount = $discount;
        $product->dis_selling_price = $dis_selling_price;
        $product->selling_price = $request->selling_price !== null ? (float) $request->selling_price : ($dis_selling_price - $discount);
        $product->status = $request->status;
        $product->description = $request->description;
        $product->imei = $request->imei;

        // Medicine and Expire Date Configuration
        $product->product_type = $request->product_type ?? 'general';
        $product->generic_name = $request->generic_name;
        $product->company = $request->company;
        $product->strength = $request->strength;
        $product->dosage_form = $request->dosage_form;
        $product->has_expire_date = $request->has_expire_date ? 1 : 0;

        // Warranty configuration
        $product->warranty_id = $request->warranty_id ?? null;
        if ($request->filled('warranty_id')) {
            $selectedWarranty = \App\Models\Warranty::find($request->warranty_id);
            if ($selectedWarranty) {
                $product->has_warranty = 1;
                $product->warranty_value = $request->filled('warranty_value') ? $request->warranty_value : $selectedWarranty->duration;
                $product->warranty_unit = $request->filled('warranty_unit') ? $request->warranty_unit : $selectedWarranty->period;
            }
        } elseif ($request->filled('warranty_value') || $request->has_warranty == 1) {
            $product->has_warranty = 1;
            $product->warranty_value = $request->warranty_value ?? null;
            $product->warranty_unit = $request->warranty_unit ?? 'Month';
        } else {
            $product->has_warranty = 0;
            $product->warranty_value = null;
            $product->warranty_unit = null;
        }

        if ($product->save()) {

            $sizes = $request->size ?? [];
            $colors = $request->color ?? [];

            $existing = ProductVariation::where('product_id', $product->id)->get();

            foreach ($existing as $old) {
                if ($old->hasTransactions()) {
                    if ($old->size_id && !in_array($old->size_id, $sizes)) {
                        $sizes[] = (string)$old->size_id;
                    }
                    if ($old->color_id && !in_array($old->color_id, $colors)) {
                        $colors[] = (string)$old->color_id;
                    }
                }
            }

            $new = collect();

            // CASE 1: Size + Color Both Provided
            if (!empty($sizes) && !empty($colors)) {
                foreach ($sizes as $size) {
                    foreach ($colors as $color) {
                        $new->push([$size, $color]);

                        ProductVariation::updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'size_id' => $size,
                                'color_id' => $color
                            ],
                            []
                        );
                    }
                }
            }

            // CASE 2: Only Size Provided
            if (!empty($sizes) && empty($colors)) {
                foreach ($sizes as $size) {
                    $new->push([$size, null]);

                    ProductVariation::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'size_id' => $size,
                            'color_id' => null
                        ],
                        []
                    );
                }
            }

            // CASE 3: Only Color Provided
            if (empty($sizes) && !empty($colors)) {
                foreach ($colors as $color) {
                    $new->push([null, $color]);

                    ProductVariation::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'size_id' => null,
                            'color_id' => $color
                        ],
                        []
                    );
                }
            }

            // DELETE MISSING VARIATIONS
            foreach ($existing as $old) {
                $exists = $new->contains(function ($v) use ($old) {
                    return $v[0] == $old->size_id && $v[1] == $old->color_id;
                });

                if (!$exists) {
                    $old->delete();
                }
            }
        }

        // if ($product->save()) {
        //     $newSizes = $request->size ?? [];
        //     $newColors = $request->color ?? [];

        //     $desiredCombinations = [];
        //     foreach ($newSizes as $sizeId) {
        //         foreach ($newColors as $colorId) {
        //             $desiredCombinations[] = ['size_id' => $sizeId, 'color_id' => $colorId];
        //         }
        //     }
        //     $existingCombinations = $product->product_variations()->get(['id', 'size_id', 'color_id']);
        //     $existingMap = [];
        //     foreach ($existingCombinations as $variation) {
        //         $existingMap[$variation->size_id . '-' . $variation->color_id] = $variation->id;
        //     }
        //     foreach ($desiredCombinations as $combo) {
        //         $key = $combo['size_id'] . '-' . $combo['color_id'];
        //         if (!isset($existingMap[$key])) {
        //             $product->product_variations()->create($combo);
        //         } else {
        //             unset($existingMap[$key]);
        //         }
        //     }
        //     if (!empty($existingMap)) {
        //         $product->product_variations()->whereIn('id', array_values($existingMap))->delete();
        //     }
        // }


        DB::transaction(function () use ($request, $product) {
            if ($product->save()) {
                if (auth()->user()->branch_id == 1) {
                    $selectedBranchIds = $request->branch_id ?? [];
                    BranchProduct::where('product_id', $product->id)
                        ->whereNotIn('branch_id', $selectedBranchIds)
                        ->delete();
                    foreach ($selectedBranchIds as $branch_id) {
                        BranchProduct::updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'branch_id' => $branch_id,
                            ]
                        );
                    }
                } else {
                    $branch_id = auth()->user()->branch_id;
                    BranchProduct::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'branch_id' => $branch_id,
                        ]
                    );
                }
            }
        });

        $image = $request->file('images');
        if ($image) {
            $imgName = date('YmdHi') . $image->getClientOriginalName();
            $image->move(public_path('uploads/products/'), $imgName);
            if (file_exists(public_path('uploads/products/' . $product->images)) and !empty($product->images)) {
                unlink(public_path('uploads/products/' . $product->images));
            }
            $product->images = $imgName;
        } else {
            $product->images = $request->old_image;
        }
        
        $product->save();
        $product->load('category', 'brand');
        logActivity('Update Product', "Product '{$product->name}' updated", $product);

        session()->flash('success', __('Product update successfully!'));
        return Redirect()->route('product.index');
    }

    public function updateRacks(Request $request, $id)
    {
        $product = Product::with('racks')->findOrFail($id);
        $rackIds = $request->rack_ids ?? [];
        $product->racks()->sync($rackIds);
        $updatedRacks = $product->racks()->get(['racks.id', 'racks.name']);

        return response()->json([
            'success' => true,
            'message' => __('Rack updated successfully for :name', ['name' => $product->name]),
            'product_id' => $product->id,
            'racks' => $updatedRacks,
        ]);
    }

    public function destroy(string $id)
    {
        $product = Product::findorfail($id);

        if ($product->hasTransactions()) {
            session()->flash('error', __('Cannot delete product because it has purchase or sales transaction history.'));
            return back();
        }
        
        // Capture info BEFORE delete
        $product->load('category', 'brand');
        logActivity('Delete Product', "Product '{$product->name}' deleted", $product);

        if (file_exists(public_path('uploads/products/' . $product->images)) and !empty($product->images)) {
            unlink(public_path('uploads/products/' . $product->images));
        }
        $product->delete();
        session()->flash('success', __('Successfully Product Delete!'));
        return back();
    }

    public function multipleBarcode()
    {
        return view('backend.pages.product.multiple-barcode');
    }

    public function print(Request $request)
    {
        $productIds = $request->products;   // product id array
        $variationIds = $request->variation; // variation id array
        $qtys = $request->qty;              // qty array

        $settings = [
            'b_company' => $request->has('b_company'),
            'b_category' => $request->has('b_category'),
            'b_name' => $request->has('b_name'),
            'b_variation' => $request->has('b_variation'),
            'b_price' => $request->has('b_price'),
            'b_vat' => $request->has('b_vat'),
        ];

        $printItems = [];

        foreach ($productIds as $index => $productId) {
            $product = Product::find($productId);
            $variationId = $variationIds[$index] ?? null;
            $qty = (int)($qtys[$index] ?? 1);
            $company = get_setting('com_name');
            $currency = get_setting('com_currency');
            // Variation info load
            $variation = null;
            if ($variationId) {
                $variation = \App\Models\ProductVariation::find($variationId);
            }

            $printItems[] = [
                'company'   => $company,
                'currency'   => $currency,
                'product'   => $product,
                'variation' => $variation,
                'qty'       => $qty,
            ];
        }
        return view('backend.pages.product.print-barcode', compact('printItems', 'settings'));
    }


    public function search(Request $request)
    {
        return $this->index($request);
    }

    public function ajaxSearch(Request $request)
    {
        $search = trim($request->get('q', ''));
        $userBranchId = auth()->user() ? auth()->user()->branch_id : 1;
        $filterBranchId = session('branch_filter_id', $userBranchId);
        $targetBranchId = ($userBranchId == 1) ? $filterBranchId : $userBranchId;

        $query = Product::where('is_service', 0);

        if ($targetBranchId) {
            $query->whereExists(function ($q) use ($targetBranchId) {
                $q->select(DB::raw(1))
                    ->from('branch_products')
                    ->whereColumn('branch_products.product_id', 'products.id')
                    ->where('branch_products.branch_id', $targetBranchId);
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('name', 'asc')
            ->limit(30)
            ->get(['id', 'name', 'barcode']);

        $results = $products->map(function ($item) {
            return [
                'id' => $item->id,
                'text' => $item->name . ($item->barcode ? " ({$item->barcode})" : ''),
            ];
        });

        return response()->json(['results' => $results]);
    }

    public function updateDescription(Request $request, $id)
    {
        $request->validate([
            'description' => 'nullable|string'
        ]);

        $product = Product::findOrFail($id);
        $product->description = $request->description;
        $product->save();

        return response()->json([
            'status' => 'success',
            'message' => __('Product description updated successfully'),
            'description' => $product->description
        ]);
    }
}