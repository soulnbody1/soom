<?php

declare(strict_types=1);

namespace App\Services;

use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\FirebaseCloudMessaging;
use Google\Service\FirebaseCloudMessaging\Message;
use Google\Service\FirebaseCloudMessaging\Notification;
use Google\Service\FirebaseCloudMessaging\SendMessageRequest;
use RuntimeException;

class FCMService
{
    private const DEAD_TOKEN_STATUSES = ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'];

    private ?FirebaseCloudMessaging $messaging = null;

    private ?string $projectId = null;

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $messaging = $this->messaging();

        $request = new SendMessageRequest([
            'message' => new Message([
                'token' => $token,
                'notification' => new Notification(['title' => $title, 'body' => $body]),
                'data' => array_map('strval', $data),
            ]),
        ]);

        $messaging->projects_messages->send('projects/'.$this->projectId, $request);
    }

    public function isDeadTokenFailure(\Throwable $exception): bool
    {
        if (! $exception instanceof GoogleServiceException) {
            return false;
        }

        if (in_array($exception->getCode(), [400, 404], true)) {
            return true;
        }

        $message = strtoupper($exception->getMessage());

        foreach (self::DEAD_TOKEN_STATUSES as $status) {
            if (str_contains($message, $status)) {
                return true;
            }
        }

        return false;
    }

    public function isConfigured(): bool
    {
        return is_readable($this->credentialsPath());
    }

    private function messaging(): FirebaseCloudMessaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        $credentialsPath = $this->credentialsPath();

        if (! is_readable($credentialsPath)) {
            throw new RuntimeException('FCM credentials are not readable at '.$credentialsPath.'.');
        }

        $credentials = json_decode((string) file_get_contents($credentialsPath), true);

        if (! is_array($credentials) || ! isset($credentials['project_id'])) {
            throw new RuntimeException('FCM credentials are missing a project_id.');
        }

        $client = new Client;
        $client->setAuthConfig($credentialsPath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $this->projectId = (string) $credentials['project_id'];

        return $this->messaging = new FirebaseCloudMessaging($client);
    }

    private function credentialsPath(): string
    {
        $configured = (string) config('services.firebase.fcm.credentials');

        if ($configured === '') {
            return storage_path('app/firebase/credentials.json');
        }

        if (preg_match('#^(?:[A-Za-z]:[\\/]|[\\/])#', $configured) === 1) {
            return $configured;
        }

        return base_path($configured);
    }
}
