<?php

use App\Models\TenantUser;

test('confirm password screen can be rendered', function () {
    $user = TenantUser::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();
});
