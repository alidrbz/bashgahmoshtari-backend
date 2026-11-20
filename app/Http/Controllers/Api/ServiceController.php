<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Customer;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    // فهرست خدمات برای مدیر
    public function index()
    {
        $services = Service::latest()->get();

        return response()->json([
            'success' => true,
            'services' => $services
        ]);
    }

    // فهرست تمام خدمات همراه وضعیت دسترسی مشتری
    public function customerIndex(Request $request)
    {
        $customer = Customer::where(
            'user_id',
            $request->user()->id
        )->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'پرونده مشتری برای این حساب کاربری ثبت نشده است.',
            ], 404);
        }

        $points = $customer->points;

        $services = Service::latest()->get()->map(function ($service) use ($points) {

            if (!$service->is_active) {
                $status = 'unavailable';
                $canUse = false;
                $message = 'این خدمت فعلاً توسط مدیریت ارائه نمی‌شود.';

            } elseif (
                $points < $service->min_points ||
                (
                    $service->max_points !== null &&
                    $points > $service->max_points
                )
            ) {
                $status = 'locked';
                $canUse = false;

                if ($points < $service->min_points) {
                    $message = 'برای استفاده از این خدمت به '
                        . number_format($service->min_points)
                        . ' امتیاز نیاز دارید.';
                } else {
                    $message = 'این خدمت در بازه امتیازی دیگری ارائه می‌شود.';
                }

            } else {
                $status = 'active';
                $canUse = true;
                $message = 'شما مجاز به استفاده از این خدمت هستید.';
            }

            return array_merge($service->toArray(), [
                'status' => $status,
                'can_use' => $canUse,
                'status_message' => $message,
            ]);
        });

        return response()->json([
            'success' => true,
            'customer_points' => $points,
            'services' => $services,
        ]);
    }

    // ثبت خدمت جدید
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'min_points' => ['required', 'integer', 'min:0'],
            'max_points' => ['nullable', 'integer', 'gte:min_points'],
            'image' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $service = Service::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'خدمت با موفقیت ثبت شد.',
            'service' => $service
        ], 201);
    }

    // نمایش جزئیات یک خدمت
    public function show($id)
    {
        $service = Service::findOrFail($id);

        return response()->json([
            'success' => true,
            'service' => $service
        ]);
    }

    // ویرایش خدمت
    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'min_points' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_points' => ['sometimes', 'nullable', 'integer', 'gte:min_points'],
            'image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $service->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'خدمت با موفقیت ویرایش شد.',
            'service' => $service->fresh()
        ]);
    }

    // حذف خدمت
    public function destroy($id)
    {
        $service = Service::findOrFail($id);

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'خدمت با موفقیت حذف شد.'
        ]);
    }
}
