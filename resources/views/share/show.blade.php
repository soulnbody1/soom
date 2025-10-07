<!DOCTYPE html>
<html lang="ar">

<head>
    <meta charset="UTF-8">
    <title>{{ $ad->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta property="og:title" content="{{ $ad->title }}">
    <meta property="og:description" content="{{ Str::limit($ad->description, 150) }}">
    <meta property="og:image" content="{{ count($ad->images) > 0 ? $ad->images[0]->image_path : asset('default.jpg') }}">

    <meta property="og:url" content="{{ route('share.show', $ad->id) }}">
    <meta property="og:type" content="website">
</head>

<body dir="rtl">
    <h1>جاري فتح الإعلان...</h1>
    <p>{{ $ad->title }}</p>
    <script>
        const adId = "{{ $ad->id }}";
        const deeplink = "soom://ad/" + adId;
        const playStore = "https://play.google.com/store/apps/details?id=com.soulnbody.masssooq";
        const appStore =
            "https://apps.apple.com/us/app/mass-sooq-%D8%A7%D9%84%D8%B3%D9%88%D9%82-%D8%A7%D9%84%D8%B4%D8%A7%D9%85%D9%84/id6749491273";

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
