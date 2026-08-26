<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;

it('raises when request input names an attribute the model does not declare', function (): void {
    expect(fn () => User::create([
        'name' => 'Operator',
        'email' => 'operator@example.test',
        'password' => 'correct-horse-battery-staple',
        'remember_token' => 'attacker-supplied',
    ]))->toThrow(MassAssignmentException::class);
});

it('never writes an undeclared attribute even when discarding silently', function (): void {
    // Production discards rather than raising, so assert the security property
    // holds on that path too.
    Model::preventSilentlyDiscardingAttributes(false);

    $user = User::create([
        'name' => 'Operator',
        'email' => 'operator2@example.test',
        'password' => 'correct-horse-battery-staple',
        'remember_token' => 'attacker-supplied',
    ]);

    expect($user->fresh()->remember_token)->toBeNull();
});
