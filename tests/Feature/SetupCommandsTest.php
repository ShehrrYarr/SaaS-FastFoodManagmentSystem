<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Setup commands for a real install (README / docs/DEPLOYMENT.md). */
class SetupCommandsTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_it_creates_a_super_admin_and_can_reset_the_password(): void
    {
        $this->artisan('sfms:super-admin', ['email' => 'Owner@Example.com', '--name' => 'Platform Owner'])
            ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
            ->expectsQuestion('Password again', 'a-long-secret')
            ->expectsOutputToContain('Created Super Admin owner@example.com')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'owner@example.com')->sole();
        $this->assertTrue($admin->is_super_admin);
        $this->assertNull($admin->tenant_id);
        $this->assertSame('Platform Owner', $admin->name);
        $this->assertTrue(Hash::check('a-long-secret', $admin->password));

        $this->artisan('sfms:super-admin', ['email' => 'owner@example.com'])
            ->expectsQuestion('Password (at least 8 characters)', 'another-secret')
            ->expectsQuestion('Password again', 'another-secret')
            ->expectsOutputToContain('Updated Super Admin')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('another-secret', $admin->fresh()->password));
        $this->assertSame(1, User::query()->where('email', 'owner@example.com')->count());
    }

    public function test_it_refuses_weak_or_mismatched_passwords_and_staff_emails(): void
    {
        $this->artisan('sfms:super-admin', ['email' => 'admin@example.com', '--name' => 'Admin'])
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->assertFailed();

        $this->artisan('sfms:super-admin', ['email' => 'admin@example.com', '--name' => 'Admin'])
            ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
            ->expectsQuestion('Password again', 'a-different-one')
            ->assertFailed();

        $this->makeShop('aaa');
        $this->artisan('sfms:super-admin', ['email' => 'owner@aaa.test'])
            ->expectsOutputToContain('belongs to shop staff')
            ->assertFailed();

        $this->assertFalse(User::query()->where('is_super_admin', true)->exists());
    }
}
