<?php

namespace App\Services;

use Twilio\Http\CurlClient;
use Twilio\Rest\Client;

class TwilioWhatsappService
{
    protected $twilio;

    protected $from;

    public function __construct()
    {
        $this->from = config('services.twilio.whatsapp_from');

        $this->twilio = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );

        $this->twilio->setHttpClient(new CurlClient([
            CURLOPT_TIMEOUT => (int) config('otp.whatsapp.timeout_seconds'),
            CURLOPT_CONNECTTIMEOUT => (int) config('otp.whatsapp.connect_timeout_seconds'),
        ]));
    }

    public function sendOtp(string $toPhoneNumber, string $otpCode): string
    {
        $message = $this->twilio->messages->create(
            'whatsapp:'.$toPhoneNumber,
            [
                'from' => 'whatsapp:'.$this->from,
                'contentSid' => config('services.twilio.otp_content_sid'),
                'contentVariables' => json_encode([
                    '1' => $otpCode,
                ]),
            ]
        );

        return $message->sid;
    }
}
