<?php

declare(strict_types=1);

it('serves the root route', function (): void {
    $this->get('/')->assertOk();
});
