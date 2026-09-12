<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Auth\OtpManager;

class OtpService
{
    public function __construct(
        private readonly OtpManager $otp,
        private readonly TwilioWhatsappService $twilio,
    ) {}

    public function sendOtpToWhatsApp(string $phoneNumber): bool|string
    {
        $otp = $this->otp->generate();
        $this->otp->issue($phoneNumber, $otp);

        try {
            $this->twilio->sendOtp($phoneNumber, $otp);

            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
