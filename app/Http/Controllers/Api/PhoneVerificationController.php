<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PhoneVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PhoneVerificationController extends Controller
{
    // درخواست کد ورود پیامکی
    public function sendCode(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^09\d{9}$/'],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        // پاسخ یکسان برای جلوگیری از افشای وجود شماره موبایل
        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'اگر شماره ثبت شده باشد، کد تأیید ارسال می‌شود.',
            ]);
        }

        // حذف کدهای استفاده‌نشده قبلی
        PhoneVerificationCode::where('phone', $user->phone)
            ->whereNull('used_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        PhoneVerificationCode::create([
            'phone' => $user->phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);

        // فقط برای آزمایش محلی؛ در محیط واقعی باید پنل پیامکی متصل شود.
        if (app()->environment('local')) {
            Log::info('Login verification code', [
                'phone' => $user->phone,
                'code' => $code,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'اگر شماره ثبت شده باشد، کد تأیید ارسال می‌شود.',
        ]);
    }

    // بررسی کد و ورود کاربر
    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'code' => ['required', 'digits:6'],
        ]);

        $verification = PhoneVerificationCode::where('phone', $validated['phone'])
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (!$verification || $verification->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'کد تأیید نامعتبر یا منقضی شده است.',
            ], 422);
        }

        if ($verification->attempts >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است.',
            ], 429);
        }

        if (!Hash::check($validated['code'], $verification->code_hash)) {
            $verification->increment('attempts');

            return response()->json([
                'success' => false,
                'message' => 'کد تأیید اشتباه است.',
            ], 422);
        }

        $user = User::where('phone', $validated['phone'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری پیدا نشد.',
            ], 404);
        }

        $verification->update([
            'used_at' => now(),
        ]);

        $token = $user->createToken('customer-club')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'ورود موفق بود.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
