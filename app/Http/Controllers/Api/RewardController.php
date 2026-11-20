<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Models\Customer;
use App\Models\RewardRedemption;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class RewardController extends Controller
{
    // دریافت فهرست جوایز فعال برای مشتری
   
public function index(Request $request)
{
    $customer = Customer::where('user_id', $request->user()->id)
        ->first();

    if (!$customer) {
        return response()->json([
            'success' => false,
            'message' => 'اطلاعات مشتری پیدا نشد.',
        ], 404);
    }

    $rewards = Reward::where('is_active', true)
        ->withCount([
            'redemptions as customer_redemptions_count' => function ($query) use ($customer) {
                $query->where('customer_id', $customer->id);
            }
        ])
        ->latest()
        ->get();

    return response()->json([
        'success' => true,
        'rewards' => $rewards,
    ]);
}


    // دریافت همه جوایز برای مدیر
    public function adminIndex()
    {
        return response()->json([
            'success' => true,
            'rewards' => Reward::latest()->get(),
        ]);
    }

    // ثبت جایزه جدید توسط مدیر
   
public function store(Request $request)
{
    $validated = $request->validate([
        'title' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
        'type' => ['required', 'string', 'max:100'],
        'icon' => ['nullable', 'string', 'max:50'],
        'points' => ['required', 'integer', 'min:1'],
        'max_redemptions' => ['nullable', 'integer', 'min:1'],
        'is_active' => ['required', 'boolean'],
        'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    ]);

    if ($request->hasFile('image')) {
        $validated['image'] = $request->file('image')
            ->store('rewards', 'public');
    }

    $reward = Reward::create($validated);

    return response()->json([
        'success' => true,
        'message' => 'جایزه با موفقیت ثبت شد.',
        'reward' => $reward,
    ], 201);
}


    // ویرایش جایزه توسط مدیر
   

public function update(Request $request, $id)
{
    $reward = Reward::find($id);

    if (!$reward) {
        return response()->json([
            'success' => false,
            'message' => 'جایزه موردنظر پیدا نشد.',
        ], 404);
    }

    $validated = $request->validate([
        'title' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
        'type' => ['required', 'string', 'max:100'],
        'icon' => ['nullable', 'string', 'max:50'],
        'points' => ['required', 'integer', 'min:1'],
        'max_redemptions' => ['nullable', 'integer', 'min:1'],
        'is_active' => ['required', 'boolean'],
        'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    ]);

    if ($request->hasFile('image')) {

        // حذف تصویر قبلی
        if ($reward->image) {
            Storage::disk('public')->delete($reward->image);
        }

        // ذخیره تصویر جدید
        $validated['image'] = $request->file('image')
            ->store('rewards', 'public');
    }

    $reward->update($validated);

    return response()->json([
        'success' => true,
        'message' => 'جایزه با موفقیت ویرایش شد.',
        'reward' => $reward,
    ]);
}



    // حذف جایزه توسط مدیر
   
public function destroy($id)
{
    $reward = Reward::find($id);

    if (!$reward) {
        return response()->json([
            'success' => false,
            'message' => 'جایزه موردنظر پیدا نشد.',
        ], 404);
    }

    // حذف تصویر جایزه از فضای ذخیره‌سازی
    if ($reward->image) {
        Storage::disk('public')->delete($reward->image);
    }

    $reward->delete();

    return response()->json([
        'success' => true,
        'message' => 'جایزه با موفقیت حذف شد.',
    ]);
}


    // دریافت جایزه توسط مشتری
    public function redeem(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {

            // پیدا کردن مشتری واردشده و قفل کردن رکورد
            $customer = Customer::where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (!$customer) {
                return [
                    'error' => 'اطلاعات مشتری پیدا نشد.',
                    'status' => 404,
                ];
            }

            // پیدا کردن جایزه و قفل کردن رکورد
            $reward = Reward::where('id', $id)
                ->lockForUpdate()
                ->first();

            // بررسی محدودیت تعداد دریافت جایزه توسط مشتری
if ($reward->max_redemptions !== null) {

    $redemptionCount = RewardRedemption::where('customer_id', $customer->id)
        ->where('reward_id', $reward->id)
        ->count();

    if ($redemptionCount >= $reward->max_redemptions) {
        return [
            'error' => 'شما قبلاً به سقف مجاز دریافت این جایزه رسیده‌اید.',
            'status' => 422,
        ];
    }
}

            // بررسی کافی بودن امتیاز
            if ($customer->points < $reward->points) {
                return [
                    'error' => 'امتیاز شما برای دریافت این جایزه کافی نیست.',
                    'status' => 422,
                ];
            }

            // کسر امتیاز مشتری
            $customer->points -= $reward->points;
            $customer->save();

            // تولید کد اختصاصی دریافت جایزه
            $redemptionCode = strtoupper(Str::random(12));

            // ثبت سابقه دریافت جایزه
            $redemption = RewardRedemption::create([
                'customer_id' => $customer->id,
                'reward_id' => $reward->id,
                'points_spent' => $reward->points,
                'status' => 'موفق',
                'redemption_code' => $redemptionCode,
            ]);

            // ثبت تراکنش در تاریخچه مشتری
            Transaction::create([
                'customer_id' => $customer->id,
                'title' => 'دریافت جایزه: ' . $reward->title,
                'type' => 'reward',
                'amount' => 0,
                'points' => -$reward->points,
                'status' => 'موفق',
            ]);

            return [
                'redemption' => $redemption,
                'remaining_points' => $customer->points,
                'reward_title' => $reward->title,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'جایزه با موفقیت دریافت شد.',
            'redemption' => $result['redemption'],
            'reward_title' => $result['reward_title'],
            'remaining_points' => $result['remaining_points'],
        ]);
    }
}
