<?php

declare(strict_types=1);

namespace App\Services;

use Google\Client;
use Google\Service\FirebaseCloudMessaging;
use Google\Service\FirebaseCloudMessaging\Message;
use Google\Service\FirebaseCloudMessaging\Notification;
use Google\Service\FirebaseCloudMessaging\SendMessageRequest;
use RuntimeException;

class FCMService
{
    private ?FirebaseCloudMessaging $messaging = null;

    private ?string $projectId = null;

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $request = new SendMessageRequest([
            'message' => new Message([
                'token' => $token,
                'notification' => new Notification(['title' => $title, 'body' => $body]),
                'data' => array_map('strval', $data),
            ]),
        ]);

        $this->messaging()->projects_messages->send('projects/'.$this->projectId, $request);
    }

    private function messaging(): FirebaseCloudMessaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        $credentialsPath = (string) config('services.firebase.fcm.credentials');

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
}
