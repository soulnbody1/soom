<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>حالة الدفع</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background: #f4f6f5; color: #111a1c; padding: 24px;
        }
        .card {
            background: #fff; border: 1px solid #dce3e1; border-radius: 14px; padding: 28px 24px;
            max-width: 420px; width: 100%; text-align: center; box-shadow: 0 8px 30px -20px rgba(17,26,28,.5);
        }
        .badge { display: inline-block; padding: 6px 14px; border-radius: 999px; font-size: 13px; margin-bottom: 16px; }
        .success { background: #e4f2e9; color: #1e7a4b; }
        .pending { background: #f7eedc; color: #8a5a0b; }
        .failed  { background: #f8e7e5; color: #9e2f28; }
        h1 { font-size: 19px; margin: 0 0 10px; font-weight: 600; }
        p { margin: 0 0 8px; color: #5a686b; font-size: 14px; line-height: 1.7; }
        .amount { font-size: 22px; font-weight: 600; color: #111a1c; margin: 12px 0; direction: ltr; }
        .ref { font-family: ui-monospace, Consolas, monospace; font-size: 11px; color: #8a9895; direction: ltr; word-break: break-all; }
        a.button {
            display: inline-block; margin-top: 18px; padding: 11px 22px; border-radius: 9px;
            background: #0b5b54; color: #fff; text-decoration: none; font-size: 14px;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0c1315; color: #e6edeb; }
            .card { background: #121c1e; border-color: #22302f; }
            p { color: #8fa09d; }
            .amount { color: #e6edeb; }
        }
    </style>
</head>
<body>
    <main class="card">
        @if (! $found)
            <span class="badge failed">غير معروفة</span>
            <h1>لم نتعرّف على هذه العملية</h1>
            <p>تأكد من فتح الرابط الصحيح، أو راجع حالة الدفع من داخل التطبيق.</p>
        @elseif ($outcome === 'success')
            <span class="badge success">تم الدفع</span>
            <h1>تم استلام دفعتك</h1>
            <p>سجّلنا الدفعة وأكملنا خطواتها في حسابك.</p>
        @elseif ($outcome === 'pending')
            <span class="badge pending">قيد التأكيد</span>
            <h1>ما زلنا نؤكّد الدفعة</h1>
            <p>نتحقق من الدفعة مع مزوّد الدفع. تابع الحالة من التطبيق بعد قليل.</p>
        @else
            <span class="badge failed">لم تكتمل</span>
            <h1>لم تكتمل الدفعة</h1>
            <p>لم تُخصم أي مبالغ لهذه المحاولة. يمكنك المحاولة مرة أخرى من التطبيق.</p>
        @endif

        @if ($found && $amount !== null)
            <div class="amount">{{ $currency }} {{ $amount }}</div>
        @endif

        <p class="ref">{{ $reference }}</p>

        <a class="button" href="{{ $deepLink }}">العودة إلى تطبيق سوم</a>
    </main>
</body>
</html>
