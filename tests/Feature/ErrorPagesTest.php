<?php

test('each custom error view renders its status, heading and the shared quick links', function (string $view, string $headingKey) {
    $rendered = $this->view($view);

    $rendered->assertSee(__($headingKey));
    $rendered->assertSee(__('errors.where_you_can_go'));
    $rendered->assertSee(route('dashboard'), false);
    $rendered->assertSee(route('donors.index'), false);
})->with([
    ['errors.403', 'errors.403_heading'],
    ['errors.404', 'errors.404_heading'],
    ['errors.419', 'errors.419_heading'],
    ['errors.429', 'errors.429_heading'],
    ['errors.500', 'errors.500_heading'],
    ['errors.503', 'errors.503_heading'],
]);

test('a request for a route that does not exist renders the custom 404 page', function () {
    config(['app.debug' => false]);

    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertSee(__('errors.404_heading'));
});
