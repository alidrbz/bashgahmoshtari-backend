<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

use App\Models\Customer;
use App\Models\User;

use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\PasswordResetController;


// ورود کاربران

// ورود با ایمیل یا شماره موبایل و رمز عبور
Route::post('/login', function (Request $request) {

    $validated = $request->validate([
        'email' => ['required_without:phone', 'nullable', 'email'],
        'phone' => ['required_without:email', 'nullable', 'regex:/^09\d{9}$/'],
        'password' => ['required', 'string'],
    ]);

    // پیدا کردن کاربر با ایمیل یا موبایل
    if (!empty($validated['phone'])) {
        $user = User::where('phone', $validated['phone'])->first();
    } else {
        $user = User::where('email', $validated['email'])->first();
    }

    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'اطلاعات ورود اشتباه است',
        ], 401);
    }

    $token = $user->createToken('customer-club')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'ورود موفق بود',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ],
    ]);
});



// ثبت‌نام کاربران جدید
Route::post('/register', function (Request $request) {

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users,email'],
        'phone' => ['required', 'regex:/^09\d{9}$/', 'unique:users,phone'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ], [
        'name.required' => 'وارد کردن نام الزامی است',
        'email.required' => 'وارد کردن ایمیل الزامی است',
        'email.email' => 'ایمیل معتبر نیست',
        'email.unique' => 'این ایمیل قبلاً ثبت شده است',
        'phone.required' => 'وارد کردن شماره موبایل الزامی است',
        'phone.regex' => 'شماره موبایل باید با 09 شروع شود و 11 رقم باشد',
        'phone.unique' => 'این شماره موبایل قبلاً ثبت شده است',
        'password.min' => 'رمز عبور باید حداقل 8 کاراکتر باشد',
        'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد',
    ]);

    $user = DB::transaction(function () use ($validated) {

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => $validated['password'],
            'role' => 'user',
        ]);

        Customer::create([
            'name' => $user->name,
            'level' => 'برنزی',
            'points' => 0,
            'purchases' => 0,
            'user_id' => $user->id,
        ]);

        return $user;
    });

    return response()->json([
        'success' => true,
        'message' => 'ثبت‌نام با موفقیت انجام شد',
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
        ],
    ], 201);
});

// بازیابی رمز عبور با ایمیل
Route::post('/password/forgot', [
    PasswordResetController::class,
    'sendEmailLink'
])->middleware('throttle:3,1');

// مسیر بازکردن لینک بازیابی رمز
Route::get('/password/reset/{token}', function (Request $request, string $token) {
    return redirect()->away(
        'http://localhost:4200/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $request->query('email'),
        ])
    );
})->name('password.reset');

// ثبت رمز عبور جدید
Route::post('/password/reset', [
    PasswordResetController::class,
    'resetPassword'
])->middleware('throttle:5,1');

// ورود با کد پیامکی
Route::post('/phone/send-code', [
    \App\Http\Controllers\Api\PhoneVerificationController::class,
    'sendCode'
])->middleware('throttle:3,1');

Route::post('/phone/verify-code', [
    \App\Http\Controllers\Api\PhoneVerificationController::class,
    'verifyCode'
])->middleware('throttle:10,1');


// مسیرهای نیازمند ورود
Route::middleware('auth:sanctum')->group(function () {
    // دریافت جایزه توسط مشتری
Route::post('/rewards/{id}/redeem', [RewardController::class, 'redeem']);

    // اطلاعات کاربر واردشده
    Route::get('/me', function (Request $request) {
        return response()->json([
            'success' => true,
            'user' => $request->user(),
        ]);
    });


    // خروج
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'با موفقیت خارج شدید',
        ]);
    });


    // اطلاعات مشتری داشبورد
    Route::get('/customer', function (Request $request) {

        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'پرونده مشتری برای این حساب کاربری ثبت نشده است.',
                'customer' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات مشتری با موفقیت دریافت شد',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'level' => $customer->level,
                'points' => $customer->points,
                'purchases' => $customer->purchases,
            ],
        ]);
    });


    // دریافت تراکنش‌های مشتری واردشده
    Route::get('/transactions', [TransactionController::class, 'index']);


    // مشاهده خدمات متناسب با امتیاز مشتری
    Route::get('/customer/services', [ServiceController::class, 'customerIndex']);


    // مشاهده جوایز فعال برای مشتری
    Route::get('/rewards', [RewardController::class, 'index']);


    // فقط مدیر اجازه مدیریت مشتریان، خدمات، کاربران و جوایز را دارد
    Route::middleware(\App\Http\Middleware\AdminMiddleware::class)->group(function () {

        // ثبت مشتری
        Route::post('/customers', function (Request $request) {

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'level' => ['required', 'in:برنزی,نقره‌ای,طلایی'],
                'points' => ['required', 'integer', 'min:0'],
                'purchases' => ['required', 'integer', 'min:0'],
            ]);

            $customer = Customer::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'مشتری با موفقیت ثبت شد',
                'customer' => $customer,
            ], 201);
        });


        // فهرست مشتریان
        Route::get('/customers', function () {
            return response()->json([
                'success' => true,
                'customers' => Customer::latest()->get(),
            ]);
        });


        // ویرایش مشتری
        Route::put('/customers/{id}', function (Request $request, $id) {

            $customer = Customer::find($id);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'مشتری موردنظر پیدا نشد',
                ], 404);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'level' => ['required', 'in:برنزی,نقره‌ای,طلایی'],
                'points' => ['required', 'integer', 'min:0'],
                'purchases' => ['required', 'integer', 'min:0'],
            ]);

            $customer->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'اطلاعات مشتری با موفقیت ویرایش شد',
                'customer' => $customer,
            ]);
        });


        // حذف مشتری
        Route::delete('/customers/{id}', function ($id) {

            $customer = Customer::find($id);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'مشتری موردنظر پیدا نشد',
                ], 404);
            }

            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'مشتری با موفقیت حذف شد',
            ]);
        });


        // مدیریت خدمات
        Route::get('/services', [ServiceController::class, 'index']);
        Route::post('/services', [ServiceController::class, 'store']);
        Route::get('/services/{id}', [ServiceController::class, 'show']);
        Route::put('/services/{id}', [ServiceController::class, 'update']);
        Route::delete('/services/{id}', [ServiceController::class, 'destroy']);


        // مدیریت کاربران
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);


        // مدیریت جوایز
        Route::get('/admin/rewards', [RewardController::class, 'adminIndex']);
        Route::post('/rewards', [RewardController::class, 'store']);
        Route::put('/rewards/{id}', [RewardController::class, 'update']);
        Route::delete('/rewards/{id}', [RewardController::class, 'destroy']);

    });




});
