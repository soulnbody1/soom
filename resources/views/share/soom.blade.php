<!DOCTYPE html>
<html lang="ar">

<head>
    <meta charset="UTF-8">
    <title>{{ $ad->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta property="og:title" content="soom">
    <meta property="og:description" content="تطبيق للبيع والشراء من خلال الإعلانات">
    <meta property="og:image" content="">

    <meta property="og:url" content="{{ route('open.soom') }}">
    <meta property="og:type" content="website">
</head>

<body dir="rtl">
    <h1>جاري التحويل الى التطبيق...</h1>
    <script>
        const adId = "{{ $ad->id }}";
        const deeplink = "soom://home/";
        const playStore = "https://play.google.com/store/apps/details?id=com.soulnbody.soom";
        const appStore =
            "https://apps.apple.com/us/app/soom-now-%D8%B3%D9%88%D9%85-%D8%A7%D9%84%D8%A2%D9%86/id6753927269";

        function openApp() {
            window.location.href = deeplink;
            setTimeout(() => {
                if (/android/i.test(navigator.userAgent)) {
                    window.location.href = playStore;
                } else if (/iphone|ipad|ipod/i.test(navigator.userAgent)) {
                    window.location.href = appStore;
                }
            }, 1500);
        }
        openApp();
    </script>

</body>

</html>
