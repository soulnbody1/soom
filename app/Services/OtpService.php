<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\OtpMail;
use App\Services\Auth\OtpManager;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function __construct(
        private readonly OtpManager $otp,
        private readonly TwilioWhatsappService $twilio,
    ) {}

    public function sendOtpToEmail(string $email): bool|string
    {
        $otp = $this->otp->generate();
        $this->otp->issue($email, $otp);

        try {
            Mail::to($email)->queue(new OtpMail($otp));

            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

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
