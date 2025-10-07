<?php

namespace App\Services;

use Google\Client;
use Google\Service\FirebaseCloudMessaging;
use Google\Service\FirebaseCloudMessaging\Message;
use Google\Service\FirebaseCloudMessaging\Notification;
use Google\Service\FirebaseCloudMessaging\SendMessageRequest;

class FCMService
{
    protected $messaging;
    protected $projectId;

    public function __construct()
    {
        $credentialsPath = storage_path('app/firebase/credentials.json');

        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $this->messaging = new FirebaseCloudMessaging($client);
        $this->projectId = json_decode(file_get_contents($credentialsPath), true)['project_id'];
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $data = array_map('strval', $data);
        $notification = new Notification([
            'title' => $title,
            'body' => $body,
        ]);

        $message = new Message([
            'token' => $token,
            'notification' => $notification,
            'data' => $data,
        ]);

        $request = new SendMessageRequest([
            'message' => $message,
        ]);

        $this->messaging->projects_messages->send("projects/{$this->projectId}", $request);
    }
}
