<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;
use App\Repositories\PasswordResetTokenRepository;
use App\Services\TwilioWhatsappService;

class OtpService
{
    protected $tokenRepo;
    protected $twilio;

    public function __construct(
        PasswordResetTokenRepository $tokenRepo,
        TwilioWhatsappService $twilio
    ) {
        $this->tokenRepo = $tokenRepo;
        $this->twilio = $twilio;
    }

    public function sendOtpToEmail(string $email): bool|string
    {
        $otp = random_int(1000, 9999);
        $this->tokenRepo->storeOrUpdate($email, $otp);

        try {
            Mail::to($email)->queue(new OtpMail($otp));
            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function sendOtpToWhatsApp(string $PhoneNumber): bool|string
    {
        $otp = random_int(1000, 9999);
        $this->tokenRepo->storeOrUpdateWhatsApp($PhoneNumber, $otp);

        try {
            $this->sendOtpExample($PhoneNumber, $otp);
            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    protected function sendOtpExample(string $PhoneNumber, string $otpCode): void
    {
        $this->twilio->sendOtp($PhoneNumber, $otpCode);
    }
}
