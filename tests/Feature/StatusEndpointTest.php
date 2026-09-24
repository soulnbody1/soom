<?php

it('reports that the api is available', function (): void {
    $this->getJson('/api/status')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'message' => 'الموقع يعمل الآن.',
        ]);
});

it('reports api maintenance without changing the endpoint contract', function (): void {
    config()->set('app.api_maintenance', true);

    $this->getJson('/api/status')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'maintenance',
            'message' => 'الموقع تحت الصيانة الآن، برجاء المحاولة لاحقاً.',
        ]);
});
