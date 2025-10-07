<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioWhatsappService
{
    protected $twilio;
    protected $from;

    public function __construct()
    {
        $sid    = config('services.twilio.sid');
        $token  = config('services.twilio.token');
        $this->from = config('services.twilio.whatsapp_from');
        $this->twilio = new Client($sid, $token);
    }



    public function sendOtp(string $toPhoneNumber, string $otpCode): string
{
    $message = $this->twilio->messages->create(
        "whatsapp:" . $toPhoneNumber,
        [
            'from' => "whatsapp:" . $this->from,
            'contentSid' => 'HX07984b9e78214399c34d1f344f7bb3c9',
            'contentVariables' => json_encode([
                '1' => $otpCode,
            ]),
        ]
    );

    return $message->sid;
}
}




// public function sendOtp(string $toPhoneNumber, string $otpCode): string
// {
//     $message = $this->twilio->messages->create(
//         "whatsapp:" . $toPhoneNumber,
//         [
//             'from' => "whatsapp:" . $this->from,
//             'body' => "أهلاً بك في السوق الشامل! 🎉\nرمز التحقق الخاص بك: {$otpCode}\nاستخدمه لتأكيد تسجيلك."
//         ]
//     );

//     return $message->sid;
// }
